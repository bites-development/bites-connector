<?php

declare(strict_types=1);

namespace Modules\BitesMiddleware\Providers;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\ServiceProvider;
use Modules\BitesMiddleware\Middleware\CheckAuthUser;
use Modules\BitesMiddleware\Middleware\CheckWorkspace;

class BitesServiceProvider extends ServiceProvider
{
    public function register()
    {
        // Register any bindings or other necessary services here.
    }

    public function boot()
    {
        $this->mergeConfig();
        $this->registerMigration();
        $this->publishAuth();
        $this->publishes(
            [
                __DIR__ . '/../Config/bites.php' => config_path('bites.php'),
            ],'bites'
        );
    }

    private function registerMigration()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
    }

    private function publishAuth()
    {
        $class = config('bites.CHECK_AUTH_PATH', CheckAuthUser::class);
        $kernel = $this->app->make(Kernel::class);
        $kernel->prependMiddleware($class);
        $ignoreCheckWorkspace = config('bites.IGNORE_CHECK_WORKSPACE', false);
        if(!$ignoreCheckWorkspace && auth()->check()) {
            $kernel->prependMiddleware(CheckWorkspace::class);
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

    private function mergeConfig()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../Config/bites.php',
            'bites'
        );
    }
}
