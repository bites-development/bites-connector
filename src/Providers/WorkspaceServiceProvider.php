<?php

declare(strict_types=1);

namespace Modules\BitesMiddleware\Providers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Modules\BitesMiddleware\Models\UserModel;
use Modules\BitesMiddleware\Models\Workspace;
use Modules\BitesMiddleware\Models\WorkspaceModel;
use Modules\BitesMiddleware\Models\WorkspaceUser;
use Modules\BitesMiddleware\Observers\DynamicWorkspaceObserver;
use Modules\BitesMiddleware\Observers\WorkspaceObserver;
use Modules\BitesMiddleware\Services\WorkspaceAccessService;

class WorkspaceServiceProvider extends ServiceProvider
{
    public function register()
    {
        // Register any bindings or other necessary services here.
    }

    public function boot()
    {
        $this->mergeConfig();
        $this->createDynamicMigrations();
        $this->assignWorkspaceObserver();
        $this->assignDynamicObserver();
        $this->applyRelations();
        $this->applyGlobalScope();
        $this->registerBuilderMacros();
        $this->registerQueryRewriter();
    }

    /**
     * Register custom Query Builder that automatically qualifies 'id' columns.
     * This works by replacing the default MySQL connection with our custom one.
     */
    private function registerQueryRewriter()
    {
        $filteredModules = config('bites.WORKSPACE.FILTERED_MODULES', []);
        $tables = [];
        
        foreach ($filteredModules as $filteredModule) {
            $model = new $filteredModule;
            $tables[$model->getTable()] = $model->getKeyName();
        }

        // Store tables in the custom query builder
        \Modules\BitesMiddleware\Database\WorkspaceQueryBuilder::$workspaceTables = $tables;
        
        // Register custom MySQL connection resolver
        \Illuminate\Database\Connection::resolverFor('mysql', function ($connection, $database, $prefix, $config) {
            return new \Modules\BitesMiddleware\Database\WorkspaceMySqlConnection(
                $connection,
                $database,
                $prefix,
                $config
            );
        });
        
        // Reconnect to apply the new connection class
        // Only do this if we have filtered modules
        if (!empty($tables)) {
            try {
                DB::purge('mysql');
                DB::reconnect('mysql');
            } catch (\Exception $e) {
                // Connection might not be established yet, that's OK
                // The resolver will be used when connection is first made
            }
        }
    }

    private function mergeConfig()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../Config/bites.php',
            'bites'
        );
    }

    private function assignWorkspaceObserver()
    {
        $class = config('bites.WORKSPACE.MAIN_WORKSPACE_CLASS', Workspace::class);
        $class::observe(WorkspaceObserver::class);
    }

    private function assignDynamicObserver()
    {
        $filteredModules = config('bites.WORKSPACE.FILTERED_MODULES');
        foreach ($filteredModules as $filteredModule) {
            $filteredModule::observe(DynamicWorkspaceObserver::class);
        }
    }

    private function applyGlobalScope()
    {
        $filteredModules = config('bites.WORKSPACE.FILTERED_MODULES');
        $workspaceUserModel = config('bites.WORKSPACE.WORKSPACE_USER.MODEL', WorkspaceUser::class);

        foreach ($filteredModules as $filteredModule) {
            $filteredModule::addGlobalScope(
                'workspaces',
                function ($builder) use ($filteredModule, $workspaceUserModel) {
                    // Check if workspace filtering should be ignored for this request
                    $workspaceAccess = app(WorkspaceAccessService::class);
                    if ($workspaceAccess->shouldIgnoreWorkspace()) {
                        return;
                    }
                    
                    $filteredModuleTable = (new $filteredModule)->getTable();
                    $workspaceUserTable = (new $workspaceUserModel)->getTable();
                    $workspaceModelTable = (new WorkspaceModel())->getTable();
                    $userModelTable = (new UserModel())->getTable();
                    $workspaceUserMap = config('bites.WORKSPACE.WORKSPACE_USER', []);
                    
                    // Only set select for main queries, not subqueries (withCount, etc.)
                    // Check if columns are already set (subqueries have specific columns)
                    $query = $builder->getQuery();
                    if (empty($query->columns)) {
                        $builder->select($filteredModuleTable . '.*');
                    }

                    //Workspace Access To Model
                    $builder->leftJoin(
                        $workspaceUserTable,
                        function ($join) use (
                            $filteredModule,
                            $workspaceUserTable,
                            $filteredModuleTable,
                            $workspaceUserMap
                        ) {
                            $join->on(
                                $workspaceUserTable . '.' . ( $workspaceUserMap['WORKSPACE_COLUMN'] ?? 'workspace_id' ),
                                $filteredModuleTable . '.workspace_id'
                            );
                        }
                    );

                    $builder->leftJoin(
                        $workspaceModelTable,
                        function ($join) use ($filteredModule, $workspaceModelTable, $filteredModuleTable) {
                            $join->on(
                                $workspaceModelTable . '.model_id',
                                $filteredModuleTable . '.' . (new $filteredModule)->getKeyName()
                            );
                            $join->where($workspaceModelTable . '.model_type', $filteredModule);
                        }
                    );

                    //Specific User Access To Model
                    $builder->leftJoin(
                        $userModelTable,
                        function ($join) use ($filteredModule, $userModelTable, $filteredModuleTable) {
                            $join->on(
                                $userModelTable . '.model_id',
                                $filteredModuleTable . '.' . (new $filteredModule)->getKeyName()
                            );
                            $join->on($userModelTable . '.b_user_id', DB::raw(auth()->user()?->id ?? 0));
                            $join->where($userModelTable . '.model_type', $filteredModule);
                        }
                    );


                    // Group all workspace access conditions together
                    // This prevents OR conditions from interfering with other query conditions
                    $builder->where(function ($query) use (
                        $filteredModuleTable,
                        $workspaceModelTable,
                        $workspaceUserTable,
                        $userModelTable,
                        $workspaceUserMap
                    ) {
                        //Access By Current Workspace
                        $query->where($filteredModuleTable . '.workspace_id', request()->header('ACTIVE-WORKSPACE', 0));
                        //Public Access Workspace
                        $query->orWhereNull($filteredModuleTable . '.workspace_id');

                        $query->orWhere($workspaceModelTable . '.workspace_id', request()->header('ACTIVE-WORKSPACE', 0));
                        //Access By User Inside Workspace
                        $query->orWhere($workspaceUserTable . '.'.( $workspaceUserMap['USER_COLUMN'] ?? 'b_user_id' ), auth()->user()?->id ?? 0);
                        // //Specific User Access To Model
                        $query->orWhere($userModelTable . '.b_user_id', auth()->user()?->id ?? 0);
                    });
                }
            );
            Log::error($filteredModule::query()->toSql());
            Log::error('User: ' . auth()->user()?->id ?? 0);
            Log::error('Workspace: ' . request()->header('ACTIVE-WORKSPACE', 0));
        }
    }

    private function applyRelations()
    {
        $filteredModules = config('bites.WORKSPACE.FILTERED_MODULES');
        foreach ($filteredModules as $filteredModule) {
            $filteredModule::resolveRelationUsing('workspaceMaster', function (Model $model) use ($filteredModule) {
                $class = config('bites.WORKSPACE.MASTER_MORPH_WORKSPACE_NAME', WorkspaceModel::class);

                return $model->morphMany($class, 'model');
            });
        }
    }

    private function createDynamicMigrations()
    {
        $filteredModules = config('bites.WORKSPACE.FILTERED_MODULES');
        foreach ($filteredModules as $filteredModule) {
            $table = (new $filteredModule)->getTable();
            if (Cache::get(md5($table . 'workspace_id')) == true) {
                continue;
            }
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'workspace_id')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->unsignedBigInteger('workspace_id')->nullable();
                });
            }
            Cache::forever(md5($table . 'workspace_id'), true);
        }
    }

    private function registerBuilderMacros()
    {
        $prefixColumn = function (Builder $builder, $column) {
            if ($column === '*' || str_contains($column, '.')) {
                return $column;
            }
            return $builder->getModel()->getTable() . '.' . $column;
        };

        // Note: find(), findOrFail(), whereKey(), where('id', ...) are all handled
        // by QualifyColumnsScope which rewrites 'id' to 'table.id' before query execution

        Builder::macro('count', function ($columns = '*') use ($prefixColumn) {
            if (is_array($columns)) {
                $prefixedColumns = array_map(fn($col) => $prefixColumn($this, $col), $columns);
                return $this->toBase()->count($prefixedColumns[0] ?? '*');
            }
            return $this->toBase()->count($prefixColumn($this, $columns));
        });

        Builder::macro('getCountForPagination', function ($columns = ['*']) use ($prefixColumn) {
            $columns = is_array($columns) ? $columns : [$columns];
            $prefixedColumns = array_map(fn($col) => $prefixColumn($this, $col), $columns);
            return $this->toBase()->getCountForPagination($prefixedColumns);
        });

        Builder::macro('paginate', function ($perPage = null, $columns = ['*'], $pageName = 'page', $page = null) use ($prefixColumn) {
            $columns = is_array($columns) ? $columns : [$columns];
            $prefixedColumns = array_map(fn($col) => $prefixColumn($this, $col), $columns);

            $page = $page ?: \Illuminate\Pagination\Paginator::resolveCurrentPage($pageName);
            $perPage = $perPage ?: $this->model->getPerPage();

            $total = $this->toBase()->getCountForPagination($prefixedColumns);
            $results = $total
                ? $this->forPage($page, $perPage)->get($columns)
                : $this->model->newCollection();

            return $this->paginator($results, $total, $perPage, $page, [
                'path' => \Illuminate\Pagination\Paginator::resolveCurrentPath(),
                'pageName' => $pageName,
            ]);
        });

        Builder::macro('sum', function ($column) use ($prefixColumn) {
            return $this->toBase()->sum($prefixColumn($this, $column));
        });

        Builder::macro('avg', function ($column) use ($prefixColumn) {
            return $this->toBase()->avg($prefixColumn($this, $column));
        });

        Builder::macro('min', function ($column) use ($prefixColumn) {
            return $this->toBase()->min($prefixColumn($this, $column));
        });

        Builder::macro('max', function ($column) use ($prefixColumn) {
            return $this->toBase()->max($prefixColumn($this, $column));
        });

        Builder::macro('select', function ($columns = ['*']) use ($prefixColumn) {
            $columns = is_array($columns) ? $columns : func_get_args();
            $prefixedColumns = array_map(fn($col) => $prefixColumn($this, $col), $columns);
            return $this->toBase()->select($prefixedColumns);
        });
    }

}
