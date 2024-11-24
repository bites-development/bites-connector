<?php

declare(strict_types=1);

namespace Modules\BitesMiddleware\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\BitesMiddleware\Observers\WorkspaceObserver;


class EventServiceProvider extends ServiceProvider
{
    public function boot()
    {
        parent::boot();
        $this->mergeConfigFrom(
            __DIR__ . '/../Config/bites.php',
            'bites'
        );

        $class = config('bites.WORKSPACE.MAIN_WORKSPACE_CLASS');
        $class::observe(WorkspaceObserver::class);
    }
}
