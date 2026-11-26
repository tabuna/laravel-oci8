<?php

namespace Yajra\Oci8\Query\Processors;

use DateTimeInterface;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Processors\Processor;
use LogicException;
use PDO;
use PDOStatement;
use RuntimeException;
use stdClass;
use Stringable;
use Yajra\Oci8\Oci8Connection;

/**
 * @phpstan-type ColumnMetadata object{
 *     name: string, type_name: string, type: string, precision: int|numeric-string|null,
 *     places: int|numeric-string|null, data_length: int|numeric-string|null, char_length: int|numeric-string|null,
 *     collation: string|null, nullable: int|numeric-string, default: string|null,
 *     auto_increment: int|numeric-string|null, comment: string|null, generated: string|null
 * }
 * @phpstan-type IndexMetadata object{
 *     index_owner: string, name: string, columns: string, expression: string|null, direction: string,
 *     type: string, unique: int|numeric-string, primary: int|numeric-string
 * }
 * @phpstan-type ForeignKeyMetadata object{
 *     name: string, columns: string, foreign_schema: string, foreign_table: string,
 *     foreign_columns: string, on_update: string|null, on_delete: string|null
 * }
 * @phpstan-type TypeMetadata object{name: string, schema: string, type: string, category: string, implicit: int|numeric-string}
 * @phpstan-type Index array{name: string, columns: list<string>, type: string, unique: bool, primary: bool, directions: list<string>}
 * @phpstan-type Column array{
 *     name: string, type_name: string, type: string, collation: string|null, nullable: bool, default: string|null,
 *     auto_increment: bool, comment: string|null, generation: array{type: string, expression: string|null}|null,
 *     length: int, precision: int
 * }
 */
class OracleProcessor extends Processor
{
    /**
     * Process an "insert get ID" query.
     *
     * @param  string  $sql
     * @param  list<mixed>  $values
     * @param  null|string  $sequence
     */
    public function processInsertGetId(Builder $query, $sql, $values, $sequence = null): int
    {
        $connection = $query->getConnection();

        if (! $connection instanceof Oci8Connection) {
            throw new LogicException('Inserting an Oracle ID requires an Oracle connection.');
        }

        $values = $this->incrementBySequence($values, $sequence);

        return $connection->insertGetId($sql, $values);
    }

    /**
     * Insert a new record and get the value of the primary key.
     *
     * @param  list<mixed>  $values
     * @return list<mixed>
     */
    protected function incrementBySequence(array $values, ?string $sequence = null): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 4);
        $caller = $trace[3] ?? [];
        $builder = $caller['object'] ?? null;
        $arguments = $caller['args'][1] ?? null;
        $record = is_array($arguments) ? ($arguments[0] ?? $arguments['values'] ?? null) : null;

        if ($builder instanceof EloquentBuilder) {
            $model = $builder->getModel();
            $sequenceName = $model->sequence ?? null;
            $connection = $model->getConnection();

            if (
                is_string($sequenceName)
                && $sequenceName !== ''
                && $model->incrementing
                && is_array($record)
                && ! array_key_exists($model->getKeyName(), $record)
                && $connection instanceof Oci8Connection
            ) {
                $connection->recordsHaveBeenModified();
                $values[] = $connection->getSequence()->nextValue($sequenceName);
            }
        }

        return $values;
    }

    /**
     * Bind values to PDO statement.
     *
     * @param  list<mixed>  $values
     */
    private function bindValues(Builder $query, array &$values, PDOStatement $statement, int $parameter): int
    {
        $count = count($values);
        for ($i = 0; $i < $count; $i++) {
            if (is_object($values[$i])) {
                if ($values[$i] instanceof DateTimeInterface) {
                    $values[$i] = $values[$i]->format($query->grammar->getDateFormat());
                } elseif ($values[$i] instanceof Stringable) {
                    $values[$i] = (string) $values[$i];
                } else {
                    throw new LogicException('An Oracle binding object must be convertible to a string.');
                }
            }
            $type = $this->getPdoType($values[$i]);
            $statement->bindParam($parameter, $values[$i], $type);
            $parameter++;
        }

        return $parameter;
    }

    /**
     * Get the elapsed time since a given starting point.
     *
     * @param  float  $start
     * @return float
     */
    protected function getElapsedTime($start)
    {
        return round((microtime(true) - $start) * 1000, 2);
    }

    /**
     * Get PDO Type depending on value.
     */
    private function getPdoType(mixed $value): int
    {
        if (is_int($value)) {
            return PDO::PARAM_INT;
        }

        if (is_bool($value)) {
            return PDO::PARAM_BOOL;
        }

        if (is_null($value)) {
            return PDO::PARAM_NULL;
        }

        return PDO::PARAM_STR;
    }

    /**
     * Save Query with Blob returning primary key value.
     *
     * @param  list<mixed>  $values
     * @param  list<mixed>  $binaries
     */
    public function saveLob(Builder $query, string $sql, array $values, array $binaries): int
    {
        $connection = $query->getConnection();

        if (! $connection instanceof Connection) {
            throw new LogicException('Saving an Oracle LOB requires a database connection.');
        }

        $connection->recordsHaveBeenModified();
        $start = microtime(true);

        $id = 0;
        $parameter = 1;
        $statement = $connection->getPdo()->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Could not prepare the Oracle LOB statement.');
        }

        $parameter = $this->bindValues($query, $values, $statement, $parameter);

        $countBinary = count($binaries);
        for ($i = 0; $i < $countBinary; $i++) {
            $statement->bindParam($parameter, $binaries[$i], PDO::PARAM_LOB, -1);
            $parameter++;
        }

        // bind output param for the returning clause.
        $statement->bindParam($parameter, $id, PDO::PARAM_INT, -1);

        if (! $statement->execute()) {
            return 0;
        }

        $connection->logQuery($sql, $values, $this->getElapsedTime($start));

        $id = filter_var($id, FILTER_VALIDATE_INT);

        return $id === false ? throw new RuntimeException('Oracle did not return an integer LOB ID.') : $id;
    }

    /**
     * Process the results of a column listing query.
     *
     * @param  list<object|array<string, mixed>>  $results
     * @return list<string>
     */
    public function processColumnListing(array $results): array
    {
        $mapping = function ($r) {
            /** @var stdClass&object{column_name: string} $row */
            $row = (object) $r;

            return strtolower($row->column_name);
        };

        return array_map($mapping, $results);
    }

    /**
     * Process the results of a columns query.
     *
     * @param  list<object|array<string, mixed>>  $results
     * @return list<Column>
     */
    public function processColumns($results): array
    {
        return array_map(function ($result) {
            /** @var stdClass&ColumnMetadata $result */
            $result = (object) $result;

            $type = strtolower((string) $result->type);
            $precision = (int) $result->precision;
            $places = (int) $result->places;
            $length = (int) $result->data_length;

            switch ($typeName = strtolower((string) $result->type_name)) {
                case 'number':
                    if ($precision === 19 && $places === 0) {
                        $type = 'bigint';
                    } elseif ($precision === 10 && $places === 0) {
                        $type = 'int';
                    } elseif ($precision === 5 && $places === 0) {
                        $type = 'smallint';
                    } elseif ($precision === 1 && $places === 0) {
                        $type = 'boolean';
                    } elseif ($places > 0) {
                        $type = 'decimal';
                    }

                    break;

                case 'varchar':
                case 'varchar2':
                case 'nvarchar2':
                case 'char':
                case 'nchar':
                    $length = (int) $result->char_length;
                    break;
                default:
                    $type = $typeName;
            }

            return [
                'name' => strtolower((string) $result->name),
                'type_name' => strtolower((string) $result->type_name),
                'type' => $type,
                'collation' => $result->collation ? strtolower((string) $result->collation) : null,
                'nullable' => (bool) $result->nullable,
                'default' => $result->generated ? null : $result->default,
                'auto_increment' => (bool) $result->auto_increment,
                'comment' => $result->comment != '' ? $result->comment : null,
                'generation' => $result->generated ? [
                    'type' => strtolower((string) $result->generated),
                    'expression' => $result->default,
                ] : null,
                'length' => $length,
                'precision' => $precision,
            ];
        }, $results);
    }

    /**
     * Process the results of a types query.
     *
     * @param  list<object|array<string, mixed>>  $results
     */
    public function processTypes($results): array
    {
        return array_map(function ($result) {
            /** @var stdClass&TypeMetadata $result */
            $result = (object) $result;

            return [
                'name' => strtolower((string) $result->name),
                'schema' => strtolower((string) $result->schema),
                'schema_qualified_name' => strtolower((string) $result->schema).'.'.strtolower((string) $result->name),
                'type' => strtolower((string) $result->type),
                'category' => strtolower((string) $result->category),
                'implicit' => (bool) $result->implicit,
            ];
        }, $results);
    }

    /**
     * Process the results of a columns query.
     *
     * @param  list<object|array<string, mixed>>  $results
     */
    public function processForeignKeys($results): array
    {
        return array_map(function ($result) {
            /** @var stdClass&ForeignKeyMetadata $result */
            $result = (object) $result;

            return [
                'name' => strtolower((string) $result->name),
                'columns' => explode(',', strtolower((string) $result->columns)),
                'foreign_schema' => strtolower((string) $result->foreign_schema),
                'foreign_table' => strtolower((string) $result->foreign_table),
                'foreign_columns' => explode(',', strtolower((string) $result->foreign_columns)),
                'on_update' => strtolower((string) $result->on_update),
                'on_delete' => $result->on_delete,
            ];
        }, $results);
    }

    /**
     * Process the results of an indexes query.
     *
     * @param  list<object|array<string, mixed>>  $results
     * @return list<Index>
     */
    public function processIndexes($results): array
    {
        $indexes = [];

        foreach ($results as $result) {
            /** @var stdClass&IndexMetadata $result */
            $result = (object) $result;
            $owner = (string) $result->index_owner;
            $name = (string) $result->name;
            $indexes[$owner][$name] ??= [
                'name' => strtolower($name),
                'columns' => [],
                'type' => strtolower((string) $result->type),
                'unique' => (bool) $result->unique,
                'primary' => (bool) $result->primary,
                'directions' => [],
            ];
            $indexes[$owner][$name]['columns'][] = $result->expression ?? strtolower((string) $result->columns);
            $indexes[$owner][$name]['directions'][] = strtolower((string) $result->direction);
        }

        $metadata = [];
        foreach ($indexes as $ownerIndexes) {
            foreach ($ownerIndexes as $index) {
                $metadata[] = $index;
            }
        }

        return $metadata;
    }

    /**
     * Process the results of a "select" query.
     *
     * @param  array<mixed>  $results
     * @return array<mixed>
     */
    public function processSelect(Builder $query, $results)
    {
        $connection = $query->getConnection();

        if ($connection instanceof Oci8Connection && $connection->isVersionBelow('12c') && (isset($query->limit) || isset($query->offset)) && ! $this->rnColumnSelected($query)) {
            return array_map(function ($row) {
                if (is_object($row) && isset($row->rn)) {
                    unset($row->rn);
                }

                return $row;
            }, $results);
        }

        return $results;
    }

    /**
     * Check if the rn column is selected.
     */
    private function rnColumnSelected(Builder $query): bool
    {
        foreach (($query->columns ?? []) as $column) {
            $value = trim((string) $query->getGrammar()->getValue($column));

            if ($value === 'rn' || preg_match('/\srn$/i', $value)) {
                return true;
            }
        }

        return false;
    }
}
