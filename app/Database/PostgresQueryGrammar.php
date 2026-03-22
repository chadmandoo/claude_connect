<?php

declare(strict_types=1);

namespace App\Database;

use Hyperf\Database\Query\Grammars\Grammar;

class PostgresQueryGrammar extends Grammar
{
    /**
     * Compile an insert ignore statement into SQL.
     */
    public function compileInsertOrIgnore(\Hyperf\Database\Query\Builder $query, array $values): string
    {
        return $this->compileInsert($query, $values) . ' on conflict do nothing';
    }

    /**
     * Compile an "upsert" statement into SQL.
     */
    public function compileUpsert(\Hyperf\Database\Query\Builder $query, array $values, array $uniqueBy, array $update): string
    {
        $sql = $this->compileInsert($query, $values);

        $sql .= ' on conflict (' . $this->columnize($uniqueBy) . ') do update set ';

        $parts = [];
        foreach ($update as $key => $value) {
            $parts[] = is_numeric($key)
                ? $this->wrap($value) . ' = ' . $this->wrapValue('excluded') . '.' . $this->wrap($value)
                : $this->wrap($key) . ' = ' . $this->parameter($value);
        }
        $columns = implode(', ', $parts);

        return $sql . $columns;
    }

    /**
     * Compile a "where like" clause.
     */
    protected function whereBasic(\Hyperf\Database\Query\Builder $query, $where): string
    {
        $value = $this->parameter($where['value']);

        // Use ILIKE for case-insensitive like on Postgres
        if (strtolower($where['operator'] ?? '') === 'like') {
            return $this->wrap($where['column']) . ' ilike ' . $value;
        }

        return parent::whereBasic($query, $where);
    }
}
