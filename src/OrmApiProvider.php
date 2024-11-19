<?php

namespace WizwebBe;

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
                \WizwebBe\Console\Commands\Scaffolding\GenerateApiControllersAndRoutes::class,
                \WizwebBe\Console\Commands\Scaffolding\GenerateLaravelModels::class,
                \WizwebBe\Console\Commands\Scaffolding\GenerateMigrations::class,
                \WizwebBe\Console\Commands\Scaffolding\GenerateVueComponents::class,
                \WizwebBe\Console\Commands\Scaffolding\GenerateVuexOrmModels::class,
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
