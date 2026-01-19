<?php

use Illuminate\Support\Facades\Route;

Route::get('/suggestions', 'SearchController@suggestions')->name('improvedsearch.suggestions');
Route::get('/history', 'SearchController@history')->name('improvedsearch.history');
Route::post('/clear-history', 'SearchController@clearHistory')->name('improvedsearch.clear-history');
Route::post('/clear-cache', 'SearchController@clearCache')->name('improvedsearch.clear-cache');

// Admin routes
Route::middleware('roles:admin')->group(function () {
    Route::post('/rebuild-index', 'SearchController@rebuildIndex')->name('improvedsearch.rebuild-index');
    Route::get('/statistics', 'SearchController@statistics')->name('improvedsearch.statistics');
});
