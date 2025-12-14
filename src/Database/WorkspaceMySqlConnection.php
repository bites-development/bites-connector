<?php

declare(strict_types=1);

namespace Modules\BitesMiddleware\Database;

use Illuminate\Database\MySqlConnection;
use Modules\BitesMiddleware\Database\WorkspaceQueryBuilder;

/**
 * Custom MySQL Connection that uses our WorkspaceQueryBuilder
 * to automatically qualify ambiguous 'id' columns.
 */
class WorkspaceMySqlConnection extends MySqlConnection
{
    /**
     * Get a new query builder instance.
     *
     * @return \Modules\BitesMiddleware\Database\WorkspaceQueryBuilder
     */
    public function query()
    {
        return new WorkspaceQueryBuilder(
            $this,
            $this->getQueryGrammar(),
            $this->getPostProcessor()
        );
    }
}
