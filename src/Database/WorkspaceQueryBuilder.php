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
     * Get the SQL representation of the query.
     *
     * @return string
     */
    public function toSql()
    {
        // Qualify columns before generating SQL
        try {
            $this->qualifyAmbiguousColumns();
        } catch (\Throwable $e) {
            // If qualification fails, continue without it
            \Log::debug('WorkspaceQueryBuilder qualification failed in toSql', [
                'table' => $this->from,
                'error' => $e->getMessage(),
            ]);
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
        // Qualify columns before executing query
        try {
            $this->qualifyAmbiguousColumns();
        } catch (\Throwable $e) {
            // If qualification fails, continue without it
            // This prevents breaking queries on non-workspace tables
            // Log the error for debugging but don't break the query
            \Log::debug('WorkspaceQueryBuilder qualification failed in runSelect', [
                'table' => $this->from,
                'error' => $e->getMessage(),
            ]);
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
        // Early exit checks - be extremely defensive
        
        // $this->from can be null or an Expression object, not just a string
        if (!is_string($this->from)) {
            \Log::debug('WorkspaceQueryBuilder: from is not a string', ['from' => $this->from]);
            return;
        }

        // Only qualify if there are joins (which cause ambiguity)
        if (empty($this->joins)) {
            \Log::debug('WorkspaceQueryBuilder: no joins present', ['table' => $this->from]);
            return;
        }

        // Skip if workspaceTables is empty or not configured
        if (empty(self::$workspaceTables)) {
            \Log::debug('WorkspaceQueryBuilder: workspaceTables is empty');
            return;
        }

        // Only qualify for workspace tables where we know the schema
        // For other tables, trust Laravel's query builder to handle it correctly
        if (!isset(self::$workspaceTables[$this->from])) {
            \Log::debug('WorkspaceQueryBuilder: table not in workspaceTables', [
                'table' => $this->from,
                'workspaceTables' => array_keys(self::$workspaceTables)
            ]);
            return;
        }

        $table = $this->from;
        
        // Double-check the table is actually in workspaceTables
        if (!array_key_exists($table, self::$workspaceTables)) {
            return;
        }
        
        $keyName = self::$workspaceTables[$table];

        \Log::debug('WorkspaceQueryBuilder: Starting qualification', [
            'table' => $table,
            'keyName' => $keyName,
            'joins_count' => count($this->joins),
            'wheres_count' => count($this->wheres)
        ]);

        // Get list of joined tables
        $joinedTables = $this->getJoinedTables();
        
        // Intelligently detect which columns are actually ambiguous by checking the schema
        $columnsToQualify = $this->getAmbiguousColumnsFromSchema($table, $joinedTables);
        
        // If schema detection fails or returns empty, fall back to the conservative list
        if (empty($columnsToQualify)) {
            \Log::debug('WorkspaceQueryBuilder: Schema detection returned empty, using fallback list');
            $columnsToQualify = array_unique(array_merge(self::$ambiguousColumns, [$keyName]));
        }

        // DO NOT qualify SELECT clause - see qualifySelectColumns() comment
        $this->qualifySelectColumns($table, $columnsToQualify);

        // Qualify columns in WHERE clause ONLY
        $qualified = 0;
        foreach ($this->wheres as $index => $where) {
            // Handle standard column references
            if (isset($where['column']) && is_string($where['column'])) {
                $column = $where['column'];
                // If column needs qualification and doesn't have table prefix, qualify it
                if (in_array($column, $columnsToQualify, true) && !str_contains($column, '.')) {
                    \Log::debug('WorkspaceQueryBuilder: Qualifying column', [
                        'column' => $column,
                        'qualified_as' => $table . '.' . $column
                    ]);
                    $this->wheres[$index]['column'] = $table . '.' . $column;
                    $qualified++;
                }
            }

            // Handle raw SQL in 'sql' key (used by whereRaw)
            if (isset($where['sql']) && is_string($where['sql'])) {
                $original = $where['sql'];
                $this->wheres[$index]['sql'] = $this->qualifyColumnsInSql($where['sql'], $table, $columnsToQualify);
                if ($original !== $this->wheres[$index]['sql']) {
                    \Log::debug('WorkspaceQueryBuilder: Qualified raw SQL', [
                        'original' => $original,
                        'qualified' => $this->wheres[$index]['sql']
                    ]);
                    $qualified++;
                }
            }

            // Handle 'value' key which may contain Expression objects or raw SQL
            if (isset($where['value']) && is_string($where['value'])) {
                $this->wheres[$index]['value'] = $this->qualifyColumnsInSql($where['value'], $table, $columnsToQualify);
            }
        }
        
        \Log::debug('WorkspaceQueryBuilder: Qualification complete', [
            'qualified_count' => $qualified
        ]);
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
     * Get columns that exist in multiple tables (truly ambiguous).
     * 
     * @param string $mainTable
     * @param array $joinedTables
     * @return array List of column names that exist in more than one table
     */
    protected function getAmbiguousColumnsFromSchema(string $mainTable, array $joinedTables): array
    {
        $allTables = array_merge([$mainTable], $joinedTables);
        $columnsByTable = [];
        
        // Get columns for each table from the database schema
        foreach ($allTables as $table) {
            try {
                $columns = \DB::getSchemaBuilder()->getColumnListing($table);
                $columnsByTable[$table] = $columns;
            } catch (\Exception $e) {
                // If we can't get schema for a table, skip it
                \Log::debug('WorkspaceQueryBuilder: Could not get schema for table', [
                    'table' => $table,
                    'error' => $e->getMessage()
                ]);
                continue;
            }
        }
        
        // Find columns that appear in multiple tables
        $columnCounts = [];
        foreach ($columnsByTable as $table => $columns) {
            foreach ($columns as $column) {
                if (!isset($columnCounts[$column])) {
                    $columnCounts[$column] = [];
                }
                $columnCounts[$column][] = $table;
            }
        }
        
        // Return only columns that exist in the main table AND at least one joined table
        $ambiguousColumns = [];
        foreach ($columnCounts as $column => $tables) {
            if (count($tables) > 1 && in_array($mainTable, $tables)) {
                $ambiguousColumns[] = $column;
            }
        }
        
        \Log::debug('WorkspaceQueryBuilder: Detected ambiguous columns from schema', [
            'main_table' => $mainTable,
            'joined_tables' => $joinedTables,
            'ambiguous_columns' => $ambiguousColumns
        ]);
        
        return $ambiguousColumns;
    }

    /**
     * Detect if a column likely belongs to a joined table by analyzing join conditions.
     * This helps prevent qualifying columns that belong to joined tables.
     */
    protected function columnBelongsToJoinedTable(string $column): bool
    {
        if (empty($this->joins)) {
            return false;
        }

        foreach ($this->joins as $join) {
            if (!isset($join->table) || !is_string($join->table)) {
                continue;
            }

            // Check if any join condition references this column with the joined table
            if (isset($join->clauses)) {
                foreach ($join->clauses as $clause) {
                    // Look for patterns like "joined_table.column"
                    if (isset($clause['first']) && is_string($clause['first'])) {
                        $parts = explode('.', $clause['first']);
                        if (count($parts) === 2 && $parts[1] === $column) {
                            return true; // Column belongs to joined table
                        }
                    }
                    if (isset($clause['second']) && is_string($clause['second'])) {
                        $parts = explode('.', $clause['second']);
                        if (count($parts) === 2 && $parts[1] === $column) {
                            return true; // Column belongs to joined table
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * Qualify ambiguous columns in the SELECT clause.
     * 
     * We use intelligent detection to only qualify columns that belong to the main table.
     */
    protected function qualifySelectColumns(string $table, array $columnsToQualify): void
    {
        if (empty($this->columns)) {
            return;
        }

        foreach ($this->columns as $index => $column) {
            // Skip if already qualified, is *, or is an Expression
            if (!is_string($column)) {
                continue;
            }

            if ($column === '*' || str_contains($column, '.') || str_contains($column, '(')) {
                continue;
            }

            // Skip if column likely belongs to a joined table
            if ($this->columnBelongsToJoinedTable($column)) {
                continue;
            }

            // Only qualify if it's an ambiguous column that belongs to the main table
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
