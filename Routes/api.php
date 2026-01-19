<?php

use Illuminate\Support\Facades\Route;

Route::get('/suggestions', 'SearchController@suggestions');
Route::get('/history', 'SearchController@history');
