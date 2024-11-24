<?php

declare(strict_types=1);

namespace Modules\BitesMiddleware\Providers;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use App\Http\Middleware\CheckAuthUser;
use Modules\BitesMiddleware\Models\Workspace;
use Modules\BitesMiddleware\Models\WorkspaceModel;
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
            $filteredModule::addGlobalScope('workspace', function ($builder) {
                if (request()->isJson()) {
                    $builder->where('workspace_id', request()->header('WORKSPACE_ID', 0));
                }
            });
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
                return;
            }
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'workspace_id')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->unsignedBigInteger('workspace_id')->nullable();
                });
            }
            Cache::forever(md5($table . 'workspace_id'), true);
        }
    }


    private function syncMorph($relationFunction,$data){
        // Get the current related IDs
        $existing = $this->{$relationFunction}()->pluck('id')->toArray();

        // Extract the IDs from the new data
        $newIds = array_filter(array_column($data, 'id'));

        // Find to delete
        $toDelete = array_diff($existing, $newIds);
        $this->{$relationFunction}()->whereIn('id', $toDelete)->delete();

        foreach ($data as $comment) {
            if (isset($comment['id']) && in_array($comment['id'], $existing)) {
                // Update existing
                $this->{$relationFunction}()->where('id', $comment['id'])->update($comment);
            } else {
                // Create new
                $this->{$relationFunction}()->create($comment);
            }
        }
    }

}
