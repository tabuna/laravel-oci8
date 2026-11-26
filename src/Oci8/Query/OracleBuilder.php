<?php

namespace Yajra\Oci8\Query;

use Closure;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use Stringable;
use Yajra\Oci8\Query\Grammars\OracleGrammar;
use Yajra\Oci8\Query\Processors\OracleProcessor;

/**
 * @phpstan-type Subquery Closure|Builder|EloquentBuilder<*>|Relation<*, *, *>
 */
class OracleBuilder extends Builder
{
    /**
     * Insert a new record and get the value of the primary key.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $binaries
     */
    public function insertLob(array $values, array $binaries, string $sequence = 'id'): int
    {
        /** @var OracleGrammar $grammar */
        $grammar = $this->grammar;
        $sql = $grammar->compileInsertLob($this, $values, $binaries, $sequence);

        $values = $this->cleanBindings($values);
        $binaries = $this->cleanBindings($binaries);

        /** @var OracleProcessor $processor */
        $processor = $this->processor;

        return $processor->saveLob($this, $sql, $values, $binaries);
    }

    /**
     * Update a new record with blob field.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $binaries
     */
    public function updateLob(array $values, array $binaries, string $sequence = 'id'): bool
    {
        $bindings = array_values(array_merge($values, $this->getBindings()));

        /** @var OracleGrammar $grammar */
        $grammar = $this->grammar;
        $sql = $grammar->compileUpdateLob($this, $values, $binaries, $sequence);

        $values = $this->cleanBindings($bindings);
        $binaries = $this->cleanBindings($binaries);

        /** @var OracleProcessor $processor */
        $processor = $this->processor;

        return (bool) $processor->saveLob($this, $sql, $values, $binaries);
    }

    /**
     * Add a "where in" clause to the query.
     * Split one WHERE IN clause into multiple clauses each
     * with up to 1000 expressions to avoid ORA-01795.
     *
     * @param  string  $column
     * @param  mixed  $values
     * @param  string  $boolean
     * @param  bool  $not
     */
    public function whereIn($column, $values, $boolean = 'and', $not = false): OracleBuilder
    {
        $type = $not ? 'NotIn' : 'In';

        if ($values instanceof Arrayable) {
            $values = $values->toArray();
        }

        if (is_array($values) && count($values) > 1000) {
            $chunks = array_chunk($values, 1000);

            return $this->where(function (Builder $query) use ($column, $chunks, $type, $not) {
                foreach ($chunks as $ch) {
                    $sqlClause = $not ? 'where'.$type : 'orWhere'.$type;
                    $query->{$sqlClause}($column, $ch);
                }
            }, null, null, $boolean);
        }

        return parent::whereIn($column, $values, $boolean, $not);
    }

    /**
     * Set the table which the query is targeting.
     *
     * @param  Subquery|Expression|string  $table
     * @param  string|null  $as
     * @return $this
     */
    public function from($table, $as = null): static
    {
        if ($table instanceof Closure || $table instanceof Builder || $table instanceof EloquentBuilder || $table instanceof Relation) {
            return $this->fromSub($table, $as ?? '');
        }

        return parent::from($as && (is_string($table) || $table instanceof Stringable) ? "{$table} {$as}" : $table);
    }

    /**
     * Makes "from" fetch from a subquery.
     *
     * @param  Subquery|string  $query
     * @param  string  $as
     */
    public function fromSub($query, $as): static
    {
        [$query, $bindings] = $this->compileSubquery($query);

        $expression = '('.$query.') '.$this->grammar->wrapTable($as);

        // @phpstan-ignore argument.type (The query is compiled and the alias is wrapped by the grammar.)
        return $this->fromRaw($expression, $bindings);
    }

    /**
     * Add a subquery join clause to the query.
     *
     * @param  Subquery|string  $query
     * @param  string  $as
     * @param  Closure|string  $first
     * @param  string|null  $operator
     * @param  string|null  $second
     * @param  string  $type
     * @param  bool  $where
     */
    public function joinSub($query, $as, $first, $operator = null, $second = null, $type = 'inner', $where = false): static
    {
        [$query, $bindings] = $this->compileSubquery($query);

        $expression = '('.$query.') '.$this->grammar->wrapTable($as);

        $this->addBinding($bindings, 'join');

        return $this->join(new CompiledExpression($expression), $first, $operator, $second, $type, $where);
    }

    /**
     * Add a subquery cross join to the query.
     *
     * @param  Subquery|string  $query
     * @param  string  $as
     */
    public function crossJoinSub($query, $as): static
    {
        [$query, $bindings] = $this->compileSubquery($query);

        $expression = '('.$query.') '.$this->grammar->wrapTable($as);

        $this->addBinding($bindings, 'join');

        $this->joins[] = $this->newJoinClause($this, 'cross', new CompiledExpression($expression));

        return $this;
    }

    /**
     * Add a lateral join clause to the query.
     *
     * @param  Subquery|string  $query
     */
    public function joinLateral($query, string $as, string $type = 'inner'): static
    {
        [$query, $bindings] = $this->compileSubquery($query);

        $expression = '('.$query.') '.$this->grammar->wrapTable($as);

        $this->addBinding($bindings, 'join');

        $this->joins[] = $this->newJoinLateralClause($this, $type, new CompiledExpression($expression));

        return $this;
    }

    /**
     * @param  Subquery|string  $query
     * @return array{string, list<mixed>}
     */
    private function compileSubquery($query): array
    {
        /** @var array{string, list<mixed>} $subquery */
        $subquery = $query instanceof Relation ? $this->parseSub($query) : $this->createSub($query);

        return $subquery;
    }

    /**
     * Get the count of the total records for the paginator.
     *
     * @param  array<string|Expression>  $columns
     * @return int
     */
    public function getCountForPagination($columns = ['*'])
    {
        $results = $this->runPaginationCountQuery($columns);
        $row = array_change_key_case((array) ($results[0] ?? []));
        $count = $row['aggregate'] ?? null;

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * Run a pagination count query.
     *
     * @param  array<string|Expression>  $columns
     * @return array<mixed>
     */
    protected function runPaginationCountQuery($columns = ['*'])
    {
        if ($this->groups || $this->havings) {
            $clone = $this->cloneForPaginationCount();

            if (is_null($clone->columns) && ! empty($this->joins)) {
                $table = $this->from instanceof Stringable ? (string) $this->from : $this->grammar->getValue($this->from);
                $clone->select($table.'.*');
            }

            return $this->newQuery()
                ->from(new CompiledExpression('('.$clone->toSql().') '.$this->grammar->wrap('aggregate_table')))
                ->mergeBindings($clone)
                ->setAggregate('count', $this->withoutSelectAliases($columns))
                ->get()->all();
        }

        $without = $this->unions ? ['unionOrders', 'unionLimit', 'unionOffset'] : ['columns', 'orders', 'limit', 'offset'];

        return $this->cloneWithout($without)
            ->cloneWithoutBindings($this->unions ? ['unionOrder'] : ['select', 'order'])
            ->setAggregate('count', $this->withoutSelectAliases($columns))
            ->get()->all();
    }
}
