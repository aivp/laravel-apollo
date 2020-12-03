<?php

    namespace Ling5821\LaravelApollo\Providers;

    use Carbon\Laravel\ServiceProvider;
    use Ling5821\LaravelApollo\ApolloManager;
    use Ling5821\LaravelApollo\Commands\ApolloCmd;

    class ApolloServiceProvider extends ServiceProvider
    {
        public function boot()
        {
            $this->publishes([
                                 __DIR__.'/../../config/apollo.php' => config_path('apollo.php'),
                             ], 'config');

            if ($this->app->runningInConsole()) {
                $this->commands([
                                    ApolloCmd::class,
                                ]);
            }
        }

        public function register()
        {

            $this->app->singleton(ApolloManager::class, function ($app) {
                return new ApolloManager();
            });
        }


    }