<?php

namespace WizwebBe\Console\Commands\Scaffolding;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class GenerateMigrations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'generate:migrations';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate migrations for all tables';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        // Run the migrations:generate command
        $this->info('Rather run php artisan migrate:generate');

        Artisan::call('migrate:generate');

        $output = Artisan::output();

        $this->info($output);
        $this->info('Migrations have been generated successfully.');
    }
}
