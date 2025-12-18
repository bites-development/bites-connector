<?php

declare(strict_types=1);

namespace Modules\BitesMiddleware\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\BitesMiddleware\Services\BitesPushService;

class BitesPushServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BitesPushService::class, function ($app) {
            return new BitesPushService();
        });
    }

    public function boot(): void
    {
        //
    }
}
