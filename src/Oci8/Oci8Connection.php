<?php

namespace Yajra\Oci8;

use Exception;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;
use Yajra\Oci8\Query\Grammars\OracleGrammar as QueryGrammar;
use Yajra\Oci8\Query\OracleBuilder as QueryBuilder;
use Yajra\Oci8\Query\Processors\OracleProcessor as Processor;
use Yajra\Oci8\Schema\Grammars\OracleGrammar as SchemaGrammar;
use Yajra\Oci8\Schema\OracleBuilder as SchemaBuilder;
use Yajra\Oci8\Schema\OracleSchemaState;
use Yajra\Oci8\Schema\Sequence;
use Yajra\Oci8\Schema\Trigger;
use Yajra\Pdo\Oci8;
use Yajra\Pdo\Oci8\Statement;

/**
 * @phpstan-import-type OracleConfig from \Yajra\Oci8\Connectors\OracleConnector
 *
 * @phpstan-type ProcedureBindings array<array-key, scalar|resource|object|null|array{value: mixed, type?: int, length?: int, options?: mixed}>
 */
class Oci8Connection extends Connection
{
    const RECONNECT_ERRORS = 'reconnect_errors';

    protected string $schema;

    protected Sequence $sequence;

    protected Trigger $trigger;

    protected int $maxLength = 30;

    protected string $schemaPrefix = '';

    /**
     * @param  PDO|\Closure  $pdo
     * @param  string  $database
     * @param  string  $tablePrefix
     * @param  OracleConfig  $config
     */
    public function __construct($pdo, $database = '', $tablePrefix = '', array $config = [])
    {
        parent::__construct($pdo, $database, $tablePrefix, $config);

        $this->sequence = new Sequence($this);
        $this->trigger = new Trigger($this);
        $this->schema = $config['username'] ?? '';
        $this->maxLength = intval($config['max_name_len'] ?? 30);
        $this->schemaPrefix = $config['prefix_schema'] ?? '';
    }

    /**
     * Execute an insert statement and return its generated primary key.
     *
     * @param  array<array-key, mixed>  $bindings
     */
    public function insertGetId(string $query, array $bindings = []): int
    {
        $id = $this->run($query, $bindings, function (string $query, array $bindings) {
            if ($this->pretending()) {
                return 0;
            }

            $statement = $this->prepareOracleStatement($query);

            $this->bindValues($statement, $this->prepareBindings($bindings));

            $id = 0;
            $statement->bindParam(count($bindings) + 1, $id, PDO::PARAM_INT, -1);

            $this->recordsHaveBeenModified();

            $statement->execute();

            return $id;
        });

        $id = filter_var($id, FILTER_VALIDATE_INT);

        return $id === false ? throw new RuntimeException('Oracle did not return an integer insert ID.') : $id;
    }

    /**
     * Get the current schema.
     */
    public function getSchema(): string
    {
        return empty($this->schemaPrefix) ? $this->schema : $this->schemaPrefix;
    }

    /**
     * Set current schema.
     */
    public function setSchema(string $schema): static
    {
        $this->schema = $schema;
        $sessionVars = [
            'CURRENT_SCHEMA' => $schema,
        ];

        return $this->setSessionVars($sessionVars);
    }

    /**
     * Update oracle session variables.
     *
     * @param  array<string, scalar|null>  $sessionVars
     */
    public function setSessionVars(array $sessionVars): static
    {
        $vars = [];
        foreach ($sessionVars as $option => $value) {
            if (strtoupper($option) == 'CURRENT_SCHEMA' || strtoupper($option) == 'EDITION') {
                $vars[] = "$option  = $value";
            } else {
                $vars[] = "$option  = '$value'";
            }
        }

        if ($vars) {
            $sql = 'ALTER SESSION SET '.implode(' ', $vars);
            $this->statement($sql);
        }

        return $this;
    }

    /**
     * Get the oracle sequence class.
     */
    public function getSequence(): Sequence
    {
        return $this->sequence;
    }

    /**
     * Get the oracle trigger class.
     */
    public function getTrigger(): Trigger
    {
        return $this->trigger;
    }

    /**
     * Get a schema builder instance for the connection.
     */
    public function getSchemaBuilder(): SchemaBuilder
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new SchemaBuilder($this);
    }

    /**
     * Get the server version for the connection.
     */
    public function getServerVersion(): string
    {
        $versionQuery = $this->prepareOracleStatement('SELECT BANNER FROM v$version');
        $versionQuery->execute();
        $version = $versionQuery->fetch(PDO::FETCH_OBJ);

        $banner = $version === false ? null : ($version->banner ?? null);

        if (! is_string($banner)) {
            throw new RuntimeException('Oracle did not return a version banner.');
        }

        return $banner;
    }

    /**
     * Get configured Oracle server version.
     */
    public function serverVersion(): string
    {
        $version = $this->getConfig('server_version');

        return is_scalar($version) ? (string) $version : '11g';
    }

    public function isVersionAbove(string $version): bool
    {
        return version_compare($version, $this->serverVersion(), '<');
    }

    public function isVersionAboveOrEqual(string $version): bool
    {
        return version_compare($version, $this->serverVersion(), '<=');
    }

    public function isVersionBelow(string $version): bool
    {
        return version_compare($version, $this->serverVersion(), '>');
    }

    public function isVersionBelowOrEqual(string $version): bool
    {
        return version_compare($version, $this->serverVersion(), '>=');
    }

    /**
     * Get the number of open connections for the database.
     */
    public function threadCount()
    {
        try {
            return parent::threadCount();
        } catch (QueryException $e) {
            $previous = $e->getPrevious();

            // handle "ORA-00942: table or view "SYS"."V_$SESSION" does not exist"
            if ($previous instanceof Throwable && str_contains($previous->getMessage(), 'ORA-00942')) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * Get a new query builder instance.
     */
    public function query(): QueryBuilder
    {
        return new QueryBuilder(
            $this, $this->getQueryGrammar(), $this->getPostProcessor()
        );
    }

    /**
     * Set oracle session date format.
     */
    public function setDateFormat(string $format = 'YYYY-MM-DD HH24:MI:SS'): static
    {
        $sessionVars = [
            'NLS_DATE_FORMAT' => $format,
            'NLS_TIMESTAMP_FORMAT' => $format,
        ];

        return $this->setSessionVars($sessionVars);
    }

    /**
     * Get the configured NLS_DATE_FORMAT from session variables.
     */
    public function getDateFormat(): string
    {
        $sessionVars = $this->getConfig('sessionVars') ?? [];

        $format = is_array($sessionVars) ? ($sessionVars['NLS_DATE_FORMAT'] ?? null) : null;

        return is_scalar($format) ? (string) $format : 'YYYY-MM-DD HH24:MI:SS';
    }

    /**
     * Execute a PL/SQL Function and return its value.
     * Usage: DB::executeFunction('function_name', ['binding_1' => 'hi', 'binding_n' =>
     * 'bye'], PDO::PARAM_LOB).
     *
     * @param  ProcedureBindings  $bindings
     */
    public function executeFunction(
        string $functionName,
        array $bindings = [],
        int $returnType = PDO::PARAM_STR,
        ?int $length = null
    ): mixed {
        $stmt = $this->createStatementFromFunction($functionName, $bindings);

        $stmt = $this->addBindingsToStatement($stmt, $bindings);

        $stmt->bindParam(':result', $result, $returnType, $length ?? -1);
        $stmt->execute();

        return $result;
    }

    /**
     * Execute a PL/SQL Procedure and return its results.
     *
     * Usage: DB::executeProcedure($procedureName, $bindings).
     * $bindings looks like:
     *         $bindings = [
     *                  'p_userid'  => $id
     *         ];
     *
     * @param  ProcedureBindings  $bindings
     */
    public function executeProcedure(string $procedureName, array $bindings = []): bool
    {
        $stmt = $this->createStatementFromProcedure($procedureName, $bindings);

        $stmt = $this->addBindingsToStatement($stmt, $bindings);

        return $stmt->execute();
    }

    /**
     * Execute a PL/SQL Procedure and return its cursor result.
     * Usage: DB::executeProcedureWithCursor($procedureName, $bindings).
     *
     * https://docs.oracle.com/cd/E17781_01/appdev.112/e18555/ch_six_ref_cur.htm#TDPPH218
     *
     * @param  string  $procedureName
     * @param  ProcedureBindings  $bindings
     * @return array<mixed>
     */
    public function executeProcedureWithCursor(
        $procedureName,
        array $bindings = [],
        string $cursorName = ':cursor'
    ): array {
        $pdo = $this->getPdo();

        if (! $pdo instanceof Oci8) {
            throw new RuntimeException('Oracle procedure cursors require the OCI8 PDO driver.');
        }

        $stmt = $this->createStatementFromProcedure($procedureName, $bindings, $cursorName);

        $stmt = $this->addBindingsToStatement($stmt, $bindings);

        $cursor = null;
        $stmt->bindParam($cursorName, $cursor, PDO::PARAM_STMT);
        $stmt->execute();

        if (! is_resource($cursor)) {
            throw new RuntimeException('Oracle did not return a procedure cursor.');
        }

        $statement = new Statement($cursor, $pdo, $pdo->getOptions());
        $statement->execute();
        $results = $statement->fetchAll(PDO::FETCH_OBJ);
        $statement->closeCursor();

        return $results;
    }

    /**
     * Creates sql command to run a procedure with bindings.
     *
     * @param  ProcedureBindings  $bindings
     */
    public function createSqlFromProcedure(string $procedureName, array $bindings, bool|string $cursor = false): string
    {
        $paramsString = implode(',', array_map(fn ($param) => ':'.$param, array_keys($bindings)));

        $prefix = count($bindings) ? ',' : '';
        $cursor = $cursor ? $prefix.$cursor : null;

        return sprintf('begin %s(%s%s); end;', $procedureName, $paramsString, $cursor);
    }

    /**
     * Creates statement from procedure.
     *
     * @param  string  $procedureName
     * @param  ProcedureBindings  $bindings
     */
    public function createStatementFromProcedure(
        $procedureName,
        array $bindings,
        bool|string $cursorName = false
    ): PDOStatement {
        $sql = $this->createSqlFromProcedure($procedureName, $bindings, $cursorName);

        return $this->prepareOracleStatement($sql);
    }

    /**
     * Create statement from function.
     *
     * @param  ProcedureBindings  $bindings
     */
    public function createStatementFromFunction(string $functionName, array $bindings): PDOStatement
    {
        $bindings = $bindings ? ':'.implode(', :', array_keys($bindings)) : '';

        $sql = sprintf('begin :result := %s(%s); end;', $functionName, $bindings);

        return $this->prepareOracleStatement($sql);
    }

    private function prepareOracleStatement(string $sql): PDOStatement
    {
        $statement = $this->getPdo()->prepare($sql);

        if ($statement === false) {
            throw new RuntimeException('Could not prepare the Oracle statement.');
        }

        return $statement;
    }

    /**
     * Get the default query grammar instance.
     */
    protected function getDefaultQueryGrammar(): QueryGrammar
    {
        return new QueryGrammar($this);
    }

    /**
     * Get the schema prefix.
     */
    public function getSchemaPrefix(): string
    {
        return $this->schemaPrefix;
    }

    /**
     * Set the schema prefix.
     */
    public function setSchemaPrefix(string $prefix): static
    {
        $this->schemaPrefix = $prefix;

        return $this;
    }

    /**
     * Get the max object name length.
     */
    public function getMaxLength(): int
    {
        return $this->maxLength;
    }

    /**
     * Set the object max length name settings.
     */
    public function setMaxLength(int $maxLength = 30): static
    {
        $this->maxLength = $maxLength;

        return $this;
    }

    /**
     * Get the schema state for the connection.
     *
     * @return OracleSchemaState
     */
    public function getSchemaState(?Filesystem $files = null, ?callable $processFactory = null)
    {
        return new OracleSchemaState($this, $files, $processFactory);
    }

    /**
     * Get the default schema grammar instance.
     */
    protected function getDefaultSchemaGrammar(): SchemaGrammar
    {
        return new SchemaGrammar($this);
    }

    /**
     * Get the default post processor instance.
     */
    protected function getDefaultPostProcessor(): Processor
    {
        return new Processor;
    }

    /**
     * Determine if the given database exception was caused by a unique constraint violation.
     */
    protected function isUniqueConstraintError(Exception $exception): bool
    {
        return (bool) preg_match('/ORA-00001:/i', $exception->getMessage());
    }

    /**
     * Add bindings to statement.
     *
     * @param  ProcedureBindings  $bindings
     */
    public function addBindingsToStatement(PDOStatement $stmt, array $bindings): PDOStatement
    {
        foreach ($bindings as $key => &$binding) {
            $value = &$binding;
            $type = PDO::PARAM_STR;
            $length = -1;
            $options = null;

            if (is_array($binding)) {
                $type = $binding['type'] ?? PDO::PARAM_STR;
                $length = $binding['length'] ?? -1;
                $options = $binding['options'] ?? null;
                $value = &$binding['value'];
            }

            $stmt->bindParam(':'.$key, $value, $type, $length, $options);
        }

        return $stmt;
    }

    /**
     * Determine if the given exception was caused by a lost connection.
     *
     * @param  Exception  $e
     */
    protected function causedByLostConnection(Throwable $e): bool
    {
        if (parent::causedByLostConnection($e)) {
            return true;
        }

        $lostConnectionErrors = [
            'ORA-03113',    // End-of-file on communication channel
            'ORA-03114',    // Not Connected to Oracle
            'ORA-03135',    // Connection lost contact
            'ORA-12170',    // Connect timeout occurred
            'ORA-12537',    // Connection closed
            'ORA-27146',    // Post/wait initialization failed
            'ORA-25408',    // Can not safely replay call
            'ORA-56600',    // Illegal Call
        ];

        $options = $this->config['options'] ?? [];
        $key = static::RECONNECT_ERRORS;
        $additionalErrors = is_array($options) && (is_string($key) || is_int($key)) ? ($options[$key] ?? null) : null;

        if (is_array($additionalErrors)) {
            $lostConnectionErrors = array_merge($lostConnectionErrors, array_filter($additionalErrors, is_string(...)));
        }

        return Str::contains($e->getMessage(), $lostConnectionErrors);
    }

    /**
     * Set oracle NLS session to case insensitive search & sort.
     */
    public function useCaseInsensitiveSession(): static
    {
        return $this->setSessionVars(['NLS_COMP' => 'LINGUISTIC', 'NLS_SORT' => 'BINARY_CI']);
    }

    /**
     * Set oracle NLS session to case-sensitive search & sort.
     */
    public function useCaseSensitiveSession(): static
    {
        return $this->setSessionVars(['NLS_COMP' => 'BINARY', 'NLS_SORT' => 'BINARY']);
    }

    /**
     * Bind values to their parameters in the given statement.
     *
     * @param  PDOStatement  $statement
     * @param  array<array-key, mixed>  $bindings
     */
    public function bindValues($statement, $bindings): void
    {
        foreach ($bindings as $key => $value) {
            $statement->bindValue(is_string($key) ? $key : $key + 1, $value, is_string($value) && strlen($value) > 3999 ? SQLT_CLOB : PDO::PARAM_STR);
        }
    }
}
