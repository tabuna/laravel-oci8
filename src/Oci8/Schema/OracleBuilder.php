<?php

namespace Yajra\Oci8\Schema;

use Closure;
use Illuminate\Database\Schema\Builder;
use LogicException;
use Yajra\Oci8\Oci8Connection;
use Yajra\Oci8\Schema\Grammars\OracleGrammar;

/**
 * @property Oci8Connection $connection
 * @property OracleGrammar $grammar
 */
class OracleBuilder extends Builder
{
    public OracleAutoIncrementHelper $helper;

    /**
     * @var OraclePreferences
     */
    public $ctxDdlPreferences;

    public function __construct(Oci8Connection $connection)
    {
        parent::__construct($connection);
        $this->helper = new OracleAutoIncrementHelper($connection);
        $this->ctxDdlPreferences = new OraclePreferences($connection);
    }

    /**
     * Create a new table on the schema.
     *
     * @param  string  $table
     */
    public function create($table, Closure $callback): void
    {
        $blueprint = $this->createBlueprint($table);

        $blueprint->create();

        $callback($blueprint);

        $this->ctxDdlPreferences->createPreferences($blueprint);

        $this->build($blueprint);

        $this->helper->createAutoIncrementObjects($blueprint, $table);
    }

    /**
     * Create a new command set with a Closure.
     *
     * @param  string  $table
     */
    protected function createBlueprint($table, ?Closure $callback = null): OracleBlueprint
    {
        return new OracleBlueprint($this->connection, $table, $callback);
    }

    /**
     * Changes an existing table on the schema.
     *
     * @param  string  $table
     */
    public function table($table, Closure $callback): void
    {
        $blueprint = $this->createBlueprint($table);

        $callback($blueprint);

        foreach ($blueprint->getCommands() as $command) {
            if ($command->get('name') == 'drop') {
                $this->helper->dropAutoIncrementObjects($table);
            }
        }

        $this->build($blueprint);
    }

    /**
     * Drop a table from the schema.
     *
     * @param  string  $table
     */
    public function drop($table): void
    {
        $this->helper->dropAutoIncrementObjects($table);
        if ($this->ctxDdlPreferences->checkIfCtxsysIsEnabled()) {
            $this->ctxDdlPreferences->dropPreferencesByTable($table);
        }
        parent::drop($table);
    }

    /**
     * Drop all tables from the database.
     */
    public function dropAllTables(): void
    {
        $this->ctxDdlPreferences->dropAllPreferences();
        $this->connection->statement($this->grammar->compileDropAllTables());
    }

    /**
     * Drop all views from the database.
     */
    public function dropAllViews(): void
    {
        $this->connection->statement($this->grammar->compileDropAllViews());
    }

    /**
     * Drop all types from the database.
     */
    public function dropAllTypes(): void
    {
        $this->connection->statement($this->grammar->compileDropAllTypes());
    }

    /**
     * Indicate that the table should be dropped if it exists.
     *
     * @param  string  $table
     */
    public function dropIfExists($table): void
    {
        $this->helper->dropAutoIncrementObjects($table);
        if ($this->ctxDdlPreferences->checkIfCtxsysIsEnabled()) {
            $this->ctxDdlPreferences->dropPreferencesByTable($table);
        }
        parent::dropIfExists($table);
    }

    /**
     * Get the column listing for a given table.
     *
     * @param  string  $table
     */
    public function getColumnListing($table): array
    {
        $database = $this->configuredUsername() ?? '';
        $table = $this->connection->getTablePrefix().$table;
        /** @var list<object|array<string, mixed>> $results */
        $results = $this->connection->select($this->grammar->compileColumnExists($database, $table));

        $process = [$this->connection->getPostProcessor(), 'processColumnListing'];

        if (! is_callable($process)) {
            throw new LogicException('The Oracle schema processor must provide processColumnListing.');
        }

        /** @var list<string> $columns */
        $columns = $process($results);

        return $columns;
    }

    /**
     * Parse the database object reference and extract the schema and table.
     *
     * @param  string  $reference
     * @param  string|bool|null  $withDefaultSchema
     */
    public function parseSchemaAndTable($reference, $withDefaultSchema = null): array
    {
        $parts = explode('.', $reference);

        // We will use the default schema unless the schema has been specified in the
        // query. If the schema has been specified in the query then we can use it
        // instead of a default schema configured in the connection search path.
        $schema = $this->configuredUsername();

        if (count($parts) === 2) {
            $schema = $parts[0];
            array_shift($parts);
        }

        return [$schema, $parts[0]];
    }

    /**
     * Disable foreign key constraints.
     */
    public function disableForeignKeyConstraints(): bool
    {
        return $this->connection->statement(
            $this->grammar->compileDisableForeignKeyConstraints($this->configuredUsername() ?? '')
        );
    }

    /**
     * Enable foreign key constraints.
     */
    public function enableForeignKeyConstraints(): bool
    {
        return $this->connection->statement(
            $this->grammar->compileEnableForeignKeyConstraints($this->configuredUsername() ?? '')
        );
    }

    /**
     * Get the tables that belong to the database.
     *
     * @param  string|null  $schema
     */
    public function getTables($schema = null): array
    {
        $sql = $this->grammar->compileTables($schema ?? $this->configuredUsername() ?? '');
        /** @var list<object|array<string, mixed>> $results */
        $results = $this->connection->selectFromWriteConnection($sql);

        return $this->connection->getPostProcessor()->processTables($results);
    }

    /**
     * Get the current schema for the connection.
     *
     * @return list<string>
     */
    public function getCurrentSchemaListing(): array
    {
        return [$this->connection->getSchema()];
    }

    private function configuredUsername(): ?string
    {
        $username = $this->connection->getConfig('username');

        return is_string($username) ? $username : null;
    }
}
