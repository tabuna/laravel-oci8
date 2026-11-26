<?php

namespace Yajra\Oci8\Query;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;

/**
 * SQL produced by the Oracle driver from wrapped identifiers and query fragments.
 *
 * @internal
 */
final class CompiledExpression implements Expression
{
    public function __construct(private readonly string $sql) {}

    public function getValue(Grammar $grammar): string
    {
        return $this->sql;
    }
}
