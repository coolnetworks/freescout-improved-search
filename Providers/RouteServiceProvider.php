<?php

namespace Modules\ImprovedSearch\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The module namespace to assume when generating URLs to actions.
     */
    protected $moduleNamespace = 'Modules\ImprovedSearch\Http\Controllers';

    /**
     * Define your route model bindings, pattern filters, etc.
     */
    public function boot()
    {
        parent::boot();
    }

    /**
     * Define the routes for the application.
     */
    public function map()
    {
        $this->mapApiRoutes();
        $this->mapWebRoutes();
    }

    /**
     * Define the "web" routes for the application.
     */
    protected function mapWebRoutes()
    {
        Route::group([
            'middleware' => ['web', 'auth'],
            'namespace' => $this->moduleNamespace,
            'prefix' => 'improvedsearch',
        ], function () {
            require __DIR__.'/../Routes/web.php';
        });
    }

    /**
     * Define the "api" routes for the application.
     */
    protected function mapApiRoutes()
    {
        Route::group([
            'middleware' => ['api', 'auth:api'],
            'namespace' => $this->moduleNamespace,
            'prefix' => 'api/improvedsearch',
        ], function () {
            require __DIR__.'/../Routes/api.php';
        });
    }
}
