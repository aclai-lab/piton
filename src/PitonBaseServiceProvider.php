<?php

namespace aclai\piton;

use Illuminate\Support\ServiceProvider;

class PitonBaseServiceProvider extends ServiceProvider
{
	public function boot()
	{
        if ($this->app->runningInConsole()) {
            $this->registerPublishing();
        }

		$this->registerResources();
	}

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->commands([
            Console\UpdateModels::class,
            Console\UpdateModelsWithInterface::class,
            Console\PredictByIdentifier::class,
            Console\CreateExample::class,
            #Console\CreateModelsWithPRip::class,
            #Console\CreateModelsWithSKLearnCART::class,
            #Console\CreateModelsWithWittgensteinIREP::class,
            #Console\CreateModelsWithWittgensteinRIPPERk::class,
        ]);
    }

    /**
     * Register the package resources.
     *
     * @return void
     */
	private function registerResources()
	{
		$this->loadMigrationsFrom(__DIR__.'/../database/migrations');

		$this->registerFacades();
	}

    /**
     * Register the package's publishable resources.
     *
     * @return void
     */
    protected function registerPublishing()
    {
        $this->publishes([
            __DIR__.'/../config/piton.php' => config_path('piton.php'),
        ], 'piton-config');

        $this->publishes([
            __DIR__.'/../config/prip.php' => config_path('prip.php'),
        ], 'prip-config');

        $this->publishes([
            __DIR__.'/../config/sklearn_cart.php' => config_path('sklearn_cart.php'),
        ], 'sklearn_cart-config');

        $this->publishes([
            __DIR__.'/../config/wittgenstein_irep.php' => config_path('wittgenstein_irep.php'),
        ], 'wittgenstein_irep-config');

        $this->publishes([
            __DIR__.'/../config/wittgenstein_ripperk.php' => config_path('wittgenstein_ripperk.php'),
        ], 'wittgenstein_ripperk-config');

        /* Example of configuration of piton.php */
        $this->publishes([
          __DIR__.'/../config/iris.php' => config_path('iris.php'),
        ], 'iris-config');
    }

    /**
     * Register any bindings to the app.
     *
     * @return void
     */
    protected function registerFacades()
    {
        $this->app->singleton('Piton', function ($app) {
            return new \aclai\piton\Piton();
        });

        $this->app->singleton('Utils', function ($app) {
            return new \aclai\piton\Utils();
        });
    }
}