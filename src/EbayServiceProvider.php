<?php

namespace timramseyjr\Ebay;

use Illuminate\Support\ServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use timramseyjr\Ebay\Commands\CheckEbayTokens;
use timramseyjr\Ebay\Commands\PollEbayOrders;

class EbayServiceProvider extends ServiceProvider
{
    /**
     * Perform post-registration booting of services.
     *
     * @return void
     */
    public function boot()
    {
        // $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'timramseyjr');
        // $this->loadViewsFrom(__DIR__.'/../resources/views', 'timramseyjr');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/routes.php');

        // Publishing is only necessary when using the CLI.
        if ($this->app->runningInConsole()) {

            // Publishing the configuration file.
            $this->publishes([
                __DIR__.'/../config/ebay.php' => config_path('ebay.php'),
            ], 'ebay.config');

            // Publishing the views.
            /*$this->publishes([
                __DIR__.'/../resources/views' => base_path('resources/views/vendor/timramseyjr'),
            ], 'ebay.views');*/

            // Publishing assets.
            /*$this->publishes([
                __DIR__.'/../resources/assets' => public_path('vendor/timramseyjr'),
            ], 'ebay.views');*/

            // Publishing the translation files.
            /*$this->publishes([
                __DIR__.'/../resources/lang' => resource_path('lang/vendor/timramseyjr'),
            ], 'ebay.views');*/

            // Registering package commands.
            $this->commands([
                CheckEbayTokens::class,
                PollEbayOrders::class
            ]);
        }
        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);
            //$schedule->command('ebay:checktokens')->everyMinute();
            $schedule->command('ebay:pollorders')->everyFifteenMinutes();
        });
    }

    /**
     * Register any package services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ebay.php', 'ebay');

        // Register the service the package provides.
        $this->app->singleton('ebay', function ($app) {
            return new Ebay;
        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return ['ebay'];
    }
}