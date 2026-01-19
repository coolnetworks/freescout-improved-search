<?php

use Illuminate\Support\Facades\Route;

Route::group([
    'middleware' => ['api', 'auth:api'],
    'prefix' => 'improvedsearch',
], function () {
    Route::get('/suggestions', 'SearchController@suggestions');
    Route::get('/history', 'SearchController@history');
});
