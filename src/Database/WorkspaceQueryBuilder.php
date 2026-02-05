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
        'created_at',
        'updated_at',
        'deleted_at',
        'status',
        'workspace_id',
    ];

    /**
     * Run the query as a "select" statement against the connection.
     *
     * @return array
     */
    protected function runSelect()
    {
        try {
            $this->qualifyAmbiguousColumns();
        } catch (\Exception $e) {
            // If qualification fails, continue without it
            // This prevents breaking queries on non-workspace tables
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
     * 
     * IMPORTANT: We only qualify WHERE clause columns, NOT SELECT columns.
     * This is because SELECT columns might come from joined tables and we can't
     * reliably determine which table they belong to without schema inspection.
     * 
     * WHERE clause qualification is safe because it only affects filtering logic,
     * and Laravel's query builder already handles most WHERE clauses correctly.
     */
    protected function qualifyAmbiguousColumns(): void
    {
        // $this->from can be null or an Expression object, not just a string
        if (!is_string($this->from)) {
            return;
        }

        // Only qualify if there are joins (which cause ambiguity)
        if (empty($this->joins)) {
            return;
        }

        // Only qualify for workspace tables where we know the schema
        // For other tables, trust Laravel's query builder to handle it correctly
        if (!isset(self::$workspaceTables[$this->from])) {
            return;
        }

        $table = $this->from;
        $keyName = self::$workspaceTables[$table];

        // Build list of columns to qualify - only common ambiguous columns
        // We use a conservative list to avoid qualifying columns from joined tables
        $columnsToQualify = array_unique(array_merge(self::$ambiguousColumns, [$keyName]));

        // DO NOT qualify SELECT clause - see qualifySelectColumns() comment
        $this->qualifySelectColumns($table, $columnsToQualify);

        // Qualify columns in WHERE clause ONLY
        foreach ($this->wheres as $index => $where) {
            // Handle standard column references
            if (isset($where['column']) && is_string($where['column'])) {
                $column = $where['column'];
                // If column needs qualification and doesn't have table prefix, qualify it
                if (in_array($column, $columnsToQualify, true) && !str_contains($column, '.')) {
                    $this->wheres[$index]['column'] = $table . '.' . $column;
                }
            }

            // Handle raw SQL in 'sql' key (used by whereRaw)
            if (isset($where['sql']) && is_string($where['sql'])) {
                $this->wheres[$index]['sql'] = $this->qualifyColumnsInSql($where['sql'], $table, $columnsToQualify);
            }

            // Handle 'value' key which may contain Expression objects or raw SQL
            if (isset($where['value']) && is_string($where['value'])) {
                $this->wheres[$index]['value'] = $this->qualifyColumnsInSql($where['value'], $table, $columnsToQualify);
            }
        }
    }

    /**
     * Qualify ambiguous columns in the SELECT clause.
     * 
     * NOTE: We intentionally DO NOT qualify SELECT columns because:
     * 1. Laravel's query builder already handles column selection properly
     * 2. Qualifying SELECT columns can break queries with joins where columns come from different tables
     * 3. The main ambiguity issue is in WHERE clauses, not SELECT clauses
     */
    protected function qualifySelectColumns(string $table, array $columnsToQualify): void
    {
        // Intentionally left empty - do not qualify SELECT columns
        // This prevents breaking queries like permissions joins where columns come from multiple tables
        return;
    }

    /**
     * Qualify column names within a SQL string.
     */
    protected function qualifyColumnsInSql(string $sql, string $table, array $columns): string
    {
        foreach ($columns as $col) {

            $pattern = '/(?<![.\w`])(' . preg_quote($col, '/') . ')(?![.\w])/';
            $replacement = $table . '.$1';
            $sql = preg_replace($pattern, $replacement, $sql);
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
