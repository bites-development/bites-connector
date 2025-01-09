<?php

declare(strict_types=1);

namespace Modules\BitesMiddleware\Providers;

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
        foreach ($filteredModules as $filteredModule) {
            $filteredModule::addGlobalScope('workspaces', function ($builder) use ($filteredModule) {
                $filteredModuleTable = ((new $filteredModule))->getTable();
                $workspaceTable = (new WorkspaceUser())->getTable();
                $workspaceModelTable = (new WorkspaceModel())->getTable();
                $userModelTable = (new UserModel())->getTable();

                $builder->select($filteredModuleTable . '.*');

                //Workspace Access To Model
                $builder->leftJoin(
                    $workspaceTable,
                    function ($join) use ($filteredModule, $workspaceTable, $filteredModuleTable) {
                        $join->on(
                            $workspaceTable . '.workspace_id',
                            $filteredModuleTable . '.workspace_id'
                        );
                    }
                );

                $builder->leftJoin(
                    $workspaceModelTable,
                    function ($join) use ($filteredModule, $workspaceModelTable, $filteredModuleTable) {
                        $join->on($workspaceModelTable.'.model_id', $filteredModuleTable . '.' . (new $filteredModule)->getKeyName());
                        $join->where($workspaceModelTable.'.model_type',$filteredModule);
                    }
                );

                //Specific User Access To Model
                $builder->leftJoin(
                    $userModelTable,
                    function ($join) use ($filteredModule, $userModelTable, $filteredModuleTable) {
                        $join->on($userModelTable.'.model_id', $filteredModuleTable . '.' . (new $filteredModule)->getKeyName());
                        $join->on($userModelTable . '.b_user_id', DB::raw(request()->user()?->id ?? 0));
                        $join->where($userModelTable.'.model_type',$filteredModule);
                    }
                );


                //Access By Current Workspace
                $builder->where($filteredModuleTable . '.workspace_id', request()->header('ACTIVE-WORKSPACE', 0));
                //Public Access Workspace
                $builder->orWhereNull($filteredModuleTable . '.workspace_id');

                $builder->orWhere($workspaceModelTable . '.workspace_id', request()->header('ACTIVE-WORKSPACE', 0));
                // //Access By User Inside Workspace
                $builder->orWhere($workspaceTable . '.b_user_id', request()->user()?->id ?? 0);
                // //Specific User Access To Model
                $builder->orWhere($userModelTable . '.b_user_id', request()->user()?->id ?? 0);
            });
            Log::error($filteredModule::query()->toSql());
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

}
