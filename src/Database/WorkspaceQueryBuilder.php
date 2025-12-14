<?php

declare(strict_types=1);

namespace Modules\BitesMiddleware\Database;

use Illuminate\Database\Query\Builder;

/**
 * Custom Query Builder that automatically qualifies ambiguous 'id' columns
 * before query execution.
 */
class WorkspaceQueryBuilder extends Builder
{
    /**
     * Tables that need id column qualification.
     * Set by WorkspaceServiceProvider.
     */
    public static array $workspaceTables = [];

    /**
     * Run the query as a "select" statement against the connection.
     *
     * @return array
     */
    protected function runSelect()
    {
        $this->qualifyIdColumns();
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
     * Qualify ambiguous 'id' columns with table name.
     */
    protected function qualifyIdColumns(): void
    {
        if (!isset(self::$workspaceTables[$this->from])) {
            return;
        }

        $table = $this->from;
        $keyName = self::$workspaceTables[$table];

        foreach ($this->wheres as $index => $where) {
            if (isset($where['column']) && is_string($where['column'])) {
                $column = $where['column'];
                // If column is 'id' or the model's key without table prefix, qualify it
                if (($column === 'id' || $column === $keyName) && !str_contains($column, '.')) {
                    $this->wheres[$index]['column'] = $table . '.' . $column;
                }
            }
        }
    }
}
