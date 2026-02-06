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
        try {
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

        if (!isset(self::$workspaceTables[$this->from])) {
            return;
        }

        $table = $this->from;
        $keyName = self::$workspaceTables[$table];
        $joinedTables = $this->getJoinedTables();
        
        // Detect which columns are actually ambiguous by checking the schema
        $columnsToQualify = $this->getAmbiguousColumnsFromSchema($table, $joinedTables);
        
        // Fallback to conservative list if schema detection fails
        if (empty($columnsToQualify)) {
            $columnsToQualify = array_unique(array_merge(self::$ambiguousColumns, [$keyName]));
        }

        $this->qualifySelectColumns($table, $columnsToQualify);

        // Qualify columns in WHERE clause
        foreach ($this->wheres as $index => $where) {
            if (isset($where['column']) && is_string($where['column'])) {
                $column = $where['column'];
                if (in_array($column, $columnsToQualify, true) && !str_contains($column, '.')) {
                    $this->wheres[$index]['column'] = $table . '.' . $column;
                }
            }

            if (isset($where['sql']) && is_string($where['sql'])) {
                $this->wheres[$index]['sql'] = $this->qualifyColumnsInSql($where['sql'], $table, $columnsToQualify);
            }

            if (isset($where['value']) && is_string($where['value'])) {
                $this->wheres[$index]['value'] = $this->qualifyColumnsInSql($where['value'], $table, $columnsToQualify);
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
