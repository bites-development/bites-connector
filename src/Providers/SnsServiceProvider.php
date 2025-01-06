<?php

declare(strict_types=1);

namespace Modules\BitesMiddleware\Providers;

use Illuminate\Support\Facades\Route;

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
        $this->registerMigrations();
        $this->registerListeners();
    }

    public function register(): void
    {
        $this->registerRoutes();
    }

    public function mapRoutes(): void
    {
        Route::prefix('api')
            ->middleware('api')
            ->group(__DIR__. '/../Resources/routes/api.php');
    }
}
