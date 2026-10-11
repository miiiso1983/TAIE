<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/reset-password/{token}', fn () => view('welcome'))->name('password.reset');

Route::view('/welcome', 'welcome');
Route::view('/login', 'welcome');
Route::view('/register', 'welcome');
Route::view('/app', 'welcome');
Route::view('/privacy', 'welcome');
