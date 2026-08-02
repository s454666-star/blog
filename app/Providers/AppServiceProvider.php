<?php

namespace App\Providers;

use App\Contracts\RecycleBin;
use App\Http\Controllers\GetRealImageController;
use App\Services\WindowsRecycleBin;
use GuzzleHttp\Client;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind(RecycleBin::class, WindowsRecycleBin::class);

        $this->app->singleton(GetRealImageController::class, function ($app) {
            return new GetRealImageController(new Client());
        });

    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        if($this->app->environment('production')) {
            \URL::forceScheme('https');
        }
    }
}
