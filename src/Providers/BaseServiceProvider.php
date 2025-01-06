<?php

declare(strict_types=1);

namespace Modules\BitesMiddleware\Providers;

use Carbon\Laravel\ServiceProvider;
use Illuminate\Support\Facades\Event;

abstract class BaseServiceProvider extends ServiceProvider
{
    abstract public static function getModuleName(): string;

    public static function getModuleNameLower(): string
    {
        return strtolower(string: static::getModuleName());
    }

    public function registerConfig()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../Resources/config/config.php',
            self::getModuleNameLower()
        );
    }


    public function publishConfig()
    {
        $this->publishes(
            [
                __DIR__ . '/../Resources/config/config.php' => config_path(self::getModuleNameLower() . '.php'),
            ],
            self::getModuleNameLower()
        );
    }


    private function loadCachedRoutes(): void
    {
        $this->app->booted(function () {
            require $this->app->getCachedRoutesPath();
        });
    }

    public function registerMigrations()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
    }

    private function loadRoutes(): void
    {
        $this->app->call([$this, 'mapRoutes']);
    }

    public function mapRoutes(): void
    {
    }

    protected function registerRoutes(): void
    {
        $this->booted(function () {
            if ($this->app->routesAreCached()) {
                $this->loadCachedRoutes();
            } else {
                $this->loadRoutes();

                $this->app->booted(function () {
                    $this->app['router']->getRoutes()->refreshNameLookups();
                    $this->app['router']->getRoutes()->refreshActionLookups();
                });
            }
        });
    }

    protected function registerListeners()
    {
        $eventListeners = config(self::getModuleNameLower() . '.listeners', []);
        foreach ($eventListeners as $event => $listeners) {
            foreach ($listeners as $listener) {
                Event::listen($event, $listener);
            }
        }
    }

}
