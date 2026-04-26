<?php

declare(strict_types=1);

namespace Modules\BitesMiddleware\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Modules\BitesMiddleware\Events\SnsMessageReceived;
use Modules\BitesMiddleware\Listeners\WorkspaceListener;

class SnsServiceProvider extends BaseServiceProvider
{
    public static function getModuleName(): string
    {
        return 'Sns';
    }

    public function boot(): void
    {
        $this->registerConfig();
        $this->publishConfig();
        $this->publishListener();
        $this->registerMigrations();
        $this->registerListeners();
        if ($this->shouldConsumeWorkspaceSync()) {
            Event::listen(SnsMessageReceived::class, WorkspaceListener::class);
        }
    }

    public function register(): void
    {
        $this->registerRoutes();
    }

    public function publishListener(){
        $destination = app_path('Listeners/TestListener.php');
        $this->publishes(
            [
                __DIR__ . '/../Listeners/TestListener.php' => $destination,
            ],'sns'
        );
        //$this->app->terminating(function () use ($destination) {
        //    if (file_exists($destination)) {
        //        $this->replaceNamespace($destination, 'Modules\BitesMiddleware\Listeners', 'App\Listeners');
        //    }
        //});
    }

    public function mapRoutes(): void
    {
        Route::prefix('api')
            ->middleware('api')
            ->group(__DIR__. '/../Resources/routes/api.php');
    }

    private function shouldConsumeWorkspaceSync(): bool
    {
        return $this->workspaceSyncMode() === 'mirror-sns';
    }

    private function workspaceSyncMode(): string
    {
        return strtolower(trim((string) config('bites.workspace_sync_mode', 'mirror-db')));
    }
}
