<?php

declare(strict_types=1);

namespace Modules\BitesMiddleware\Database;

use Illuminate\Database\Query\Builder;

/**
 * Custom Query Builder that automatically qualifies ambiguous columns
 * before query execution.
 */
class WorkspaceQueryBuilder extends Builder
{
    /**
     * Tables that need column qualification.
     * Set by WorkspaceServiceProvider.
     */
    public static array $workspaceTables = [];

    /**
     * Common columns that may be ambiguous when joining workspace tables.
     */
    protected static array $ambiguousColumns = [
        'id',
        'user_id',
        'b_user_id',
        'model_id',
        'workspace',
        'created_at',
        'updated_at',
        'deleted_at',
        'status',
        'workspace_id',
    ];

    /**
     * Get the SQL representation of the query.
     *
     * @return string
     */
    public function toSql()
    {
        try {
            $this->normalizeWildcardSelectForJoinedQueries();
            $this->qualifyAmbiguousColumns();
            $this->fixIncorrectQualifications();
        } catch (\Throwable $e) {
            // Silently continue if qualification fails
        }
        
        return parent::toSql();
    }

    /**
     * Run the query as a "select" statement against the connection.
     *
     * @return array
     */
    protected function runSelect()
    {
        try {
            $this->normalizeWildcardSelectForJoinedQueries();
            $this->qualifyAmbiguousColumns();
        } catch (\Throwable $e) {
            // Silently continue if qualification fails
        }
        return parent::runSelect();
    }

    /**
     * Insert new records into the database.
     *
     * @param  array  $values
     * @return bool
     */
    public function insert(array $values)
    {
        $this->qualifyIdColumns();
        return parent::insert($values);
    }

    /**
     * Update records in the database.
     *
     * @param  array  $values
     * @return int
     */
    public function update(array $values)
    {
        $this->qualifyIdColumns();
        return parent::update($values);
    }

    /**
     * Delete records from the database.
     *
     * @param  mixed  $id
     * @return int
     */
    public function delete($id = null)
    {
        $this->qualifyIdColumns();
        return parent::delete($id);
    }

    /**
     * Qualify ambiguous columns with table name when joins are present.
     */
    protected function qualifyAmbiguousColumns(): void
    {
        if (!is_string($this->from) || empty($this->joins) || empty(self::$workspaceTables)) {
            return;
        }

        [$mainTable, $mainAlias] = $this->parseTableAndAlias($this->from);
        if ($mainTable === '') {
            return;
        }

        $tableReference = $mainAlias !== '' ? $mainAlias : $mainTable;

        $keyName = self::$workspaceTables[$mainTable]
            ?? self::$workspaceTables[$tableReference]
            ?? null;

        if ($keyName === null) {
            return;
        }

        $joinedTables = $this->getJoinedPhysicalTables();
        
        // Detect which columns are actually ambiguous by checking the schema
        $columnsToQualify = $this->getAmbiguousColumnsFromSchema($mainTable, $joinedTables);
        
        // Fallback to conservative list if schema detection fails
        if (empty($columnsToQualify)) {
            $columnsToQualify = array_unique(array_merge(self::$ambiguousColumns, [$keyName]));
        }

        $this->qualifySelectColumns($tableReference, $columnsToQualify);

        // Qualify columns in WHERE clause
        foreach ($this->wheres as $index => $where) {
            $this->qualifyWhereClause($this->wheres[$index], $tableReference, $columnsToQualify);
        }
    }

    /**
     * Prevent joined wildcard hydration collisions (e.g. joined "id" overriding base "id").
     * Only rewrites wildcard selections and leaves explicit column selections untouched.
     */
    protected function normalizeWildcardSelectForJoinedQueries(): void
    {
        if (empty($this->joins) || !is_string($this->from)) {
            return;
        }

        $baseRef = $this->getBaseTableReference();
        if ($baseRef === '') {
            return;
        }

        $joinReferenceMap = $this->getJoinReferenceMap();

        if (is_null($this->columns)) {
            $this->columns = [$baseRef . '.*'];
            return;
        }

        if (!is_array($this->columns)) {
            return;
        }

        $normalized = [];
        $changed = false;

        foreach ($this->columns as $column) {
            if (!is_string($column)) {
                $normalized[] = $column;
                continue;
            }

            $trimmed = trim($column);

            if ($trimmed === '*') {
                $normalized[] = $baseRef . '.*';
                $changed = true;
                continue;
            }

            if (preg_match('/^([`"\\[\\]\\w]+)\\.\\*$/', $trimmed, $matches) === 1) {
                $wildcardRef = trim($matches[1], '`"[]');

                if ($wildcardRef !== $baseRef && isset($joinReferenceMap[$wildcardRef])) {
                    $expanded = $this->expandJoinWildcardSelect(
                        $wildcardRef,
                        $joinReferenceMap[$wildcardRef]
                    );

                    if (!empty($expanded)) {
                        foreach ($expanded as $expandedColumn) {
                            $normalized[] = $expandedColumn;
                        }
                        $changed = true;
                        continue;
                    }
                }
            }

            $normalized[] = $column;
        }

        if ($changed) {
            $this->columns = $normalized;
        }
    }

    /**
     * Resolve the base table reference used in SELECTs.
     * If FROM uses an alias, prefer the alias.
     */
    protected function getBaseTableReference(): string
    {
        $from = trim($this->from);
        if ($from === '' || str_starts_with($from, '(')) {
            return '';
        }

        if (preg_match('/\\s+as\\s+([`"\\[\\]\\w]+)$/i', $from, $matches) === 1) {
            return trim($matches[1], '`"[]');
        }

        if (preg_match('/\\s+([`"\\[\\]\\w]+)$/', $from, $matches) === 1 && str_contains($from, ' ')) {
            return trim($matches[1], '`"[]');
        }

        return trim($from, '`"[]');
    }

    /**
     * Map join references (alias or table name) to physical table names.
     *
     * @return array<string, string>
     */
    protected function getJoinReferenceMap(): array
    {
        $map = [];

        foreach ($this->joins as $join) {
            if (!isset($join->table) || !is_string($join->table)) {
                continue;
            }

            [$tableName, $alias] = $this->parseTableAndAlias($join->table);
            if ($tableName === '') {
                continue;
            }

            $map[$tableName] = $tableName;

            if ($alias !== '') {
                $map[$alias] = $tableName;
            }
        }

        return $map;
    }

    /**
     * Expand "<join_alias>.*" into aliased columns to prevent hydration collisions.
     *
     * @return array<int, string>
     */
    protected function expandJoinWildcardSelect(string $joinReference, string $tableName): array
    {
        try {
            $columns = \DB::getSchemaBuilder()->getColumnListing($tableName);
        } catch (\Throwable $e) {
            return [];
        }

        if (empty($columns)) {
            return [];
        }

        $aliasPrefix = preg_replace('/[^A-Za-z0-9_]/', '_', $joinReference) ?: $joinReference;
        $expanded = [];

        foreach ($columns as $column) {
            $expanded[] = $joinReference . '.' . $column . ' as ' . $aliasPrefix . '__' . $column;
        }

        return $expanded;
    }

    /**
     * Parse "table", "table alias", or "table as alias".
     *
     * @return array{0:string,1:string}
     */
    protected function parseTableAndAlias(string $reference): array
    {
        $reference = trim($reference);
        if ($reference === '') {
            return ['', ''];
        }

        if (preg_match('/^([`"\\[\\]\\w.]+)\\s+as\\s+([`"\\[\\]\\w]+)$/i', $reference, $matches) === 1) {
            return [trim($matches[1], '`"[]'), trim($matches[2], '`"[]')];
        }

        if (preg_match('/^([`"\\[\\]\\w.]+)\\s+([`"\\[\\]\\w]+)$/', $reference, $matches) === 1) {
            return [trim($matches[1], '`"[]'), trim($matches[2], '`"[]')];
        }

        return [trim($reference, '`"[]'), ''];
    }

    /**
     * Qualify ambiguous columns for a where clause, including nested wheres.
     */
    protected function qualifyWhereClause(array &$where, string $table, array $columnsToQualify): void
    {
        if (isset($where['column']) && is_string($where['column'])) {
            $column = $where['column'];
            if (in_array($column, $columnsToQualify, true) && !str_contains($column, '.')) {
                $where['column'] = $table . '.' . $column;
            }
        }

        if (isset($where['sql']) && is_string($where['sql'])) {
            $where['sql'] = $this->qualifyColumnsInSql($where['sql'], $table, $columnsToQualify);
        }

        if (isset($where['value']) && is_string($where['value'])) {
            $where['value'] = $this->qualifyColumnsInSql($where['value'], $table, $columnsToQualify);
        }

        // where(function ($q) { ... }) compiles as type=Nested and carries its own wheres.
        if (
            ($where['type'] ?? null) === 'Nested'
            && isset($where['query'])
            && $where['query'] instanceof Builder
            && is_array($where['query']->wheres ?? null)
        ) {
            foreach ($where['query']->wheres as $nestedIndex => $nestedWhere) {
                if (is_array($nestedWhere)) {
                    $this->qualifyWhereClause($where['query']->wheres[$nestedIndex], $table, $columnsToQualify);
                }
            }
        }
    }

    /**
     * Get all joined table names from the query.
     */
    protected function getJoinedTables(): array
    {
        $tables = [];
        if (!empty($this->joins)) {
            foreach ($this->joins as $join) {
                if (isset($join->table)) {
                    $tables[] = $join->table;
                }
            }
        }
        return $tables;
    }

    /**
     * Get joined physical table names (without aliases) for schema inspection.
     */
    protected function getJoinedPhysicalTables(): array
    {
        $tables = [];

        if (!empty($this->joins)) {
            foreach ($this->joins as $join) {
                if (!isset($join->table) || !is_string($join->table)) {
                    continue;
                }

                [$tableName] = $this->parseTableAndAlias($join->table);
                if ($tableName !== '') {
                    $tables[] = $tableName;
                }
            }
        }

        return array_values(array_unique($tables));
    }

    /**
     * Get columns that exist in multiple tables (truly ambiguous).
     */
    protected function getAmbiguousColumnsFromSchema(string $mainTable, array $joinedTables): array
    {
        $allTables = array_merge([$mainTable], $joinedTables);
        $columnCounts = [];
        
        foreach ($allTables as $table) {
            try {
                $columns = \DB::getSchemaBuilder()->getColumnListing($table);
                foreach ($columns as $column) {
                    if (!isset($columnCounts[$column])) {
                        $columnCounts[$column] = [];
                    }
                    $columnCounts[$column][] = $table;
                }
            } catch (\Exception $e) {
                continue;
            }
        }
        
        $ambiguousColumns = [];
        foreach ($columnCounts as $column => $tables) {
            if (count($tables) > 1 && in_array($mainTable, $tables)) {
                $ambiguousColumns[] = $column;
            }
        }
        
        return $ambiguousColumns;
    }

    /**
     * Remove unnecessary table prefixes from columns that exist in only one table.
     * Only keep prefixes for columns that exist in multiple tables (truly ambiguous).
     */
    protected function fixIncorrectQualifications(): void
    {
        // Only process if we have joins
        if (empty($this->joins) || !is_string($this->from)) {
            return;
        }

        // Get all tables involved
        $mainTable = $this->from;
        $joinedTables = $this->getJoinedTables();
        $allTables = array_merge([$mainTable], $joinedTables);

        // Build a map of which columns exist in which tables
        $columnToTables = [];
        foreach ($allTables as $table) {
            try {
                $columns = \DB::getSchemaBuilder()->getColumnListing($table);
                foreach ($columns as $column) {
                    if (!isset($columnToTables[$column])) {
                        $columnToTables[$column] = [];
                    }
                    $columnToTables[$column][] = $table;
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        // Process SELECT columns
        if (!empty($this->columns) && is_array($this->columns)) {
            foreach ($this->columns as $index => $column) {
                if (!is_string($column)) {
                    continue;
                }

                // Check if column is qualified (contains a dot)
                if (str_contains($column, '.')) {
                    [$table, $col] = explode('.', $column, 2);
                    
                    // If column exists in only ONE table, remove the prefix
                    if (isset($columnToTables[$col]) && count($columnToTables[$col]) === 1) {
                        $this->columns[$index] = $col;
                    }
                    // If column exists in multiple tables but qualified with wrong table, fix it
                    elseif (isset($columnToTables[$col]) && !in_array($table, $columnToTables[$col])) {
                        $correctTable = $columnToTables[$col][0];
                        $this->columns[$index] = $correctTable . '.' . $col;
                    }
                }
            }
        }
    }

    /**
     * Detect if a column likely belongs to a joined table.
     */
    protected function columnBelongsToJoinedTable(string $column): bool
    {
        if (empty($this->joins)) {
            return false;
        }

        foreach ($this->joins as $join) {
            if (!isset($join->table, $join->clauses)) {
                continue;
            }

            foreach ($join->clauses as $clause) {
                foreach (['first', 'second'] as $key) {
                    if (isset($clause[$key]) && is_string($clause[$key])) {
                        $parts = explode('.', $clause[$key]);
                        if (count($parts) === 2 && $parts[1] === $column) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * Qualify ambiguous columns in the SELECT clause.
     */
    protected function qualifySelectColumns(string $table, array $columnsToQualify): void
    {
        if (empty($this->columns)) {
            return;
        }

        foreach ($this->columns as $index => $column) {
            if (!is_string($column) || $column === '*' || str_contains($column, '.') || str_contains($column, '(')) {
                continue;
            }

            if ($this->columnBelongsToJoinedTable($column)) {
                continue;
            }

            if (in_array($column, $columnsToQualify, true)) {
                $this->columns[$index] = $table . '.' . $column;
            }
        }
    }

    /**
     * Qualify column names within a SQL string.
     */
    protected function qualifyColumnsInSql(string $sql, string $table, array $columns): string
    {
        foreach ($columns as $col) {
            $pattern = '/(?<![.\w`])(' . preg_quote($col, '/') . ')(?![.\w])/';
            $sql = preg_replace($pattern, $table . '.$1', $sql);
        }
        return $sql;
    }

    /**
     * @deprecated Use qualifyAmbiguousColumns() instead
     */
    protected function qualifyIdColumns(): void
    {
        $this->qualifyAmbiguousColumns();
    }
}
