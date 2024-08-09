<?php

namespace QuicklistsOrmApi;

use Illuminate\Support\ServiceProvider;

class OrmApiProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            // Register commands in the Scaffolding directory
            $this->commands([
                \QuicklistsOrmApi\Console\Commands\Scaffolding\GenerateApiControllersAndRoutes::class,
                \QuicklistsOrmApi\Console\Commands\Scaffolding\GenerateLaravelModels::class,
                \QuicklistsOrmApi\Console\Commands\Scaffolding\GenerateMigrations::class,
                \QuicklistsOrmApi\Console\Commands\Scaffolding\GenerateVueComponents::class,
                \QuicklistsOrmApi\Console\Commands\Scaffolding\GenerateVuexOrmModels::class,
            ]);
        }
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // Register any package services here
    }
}
