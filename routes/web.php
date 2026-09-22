<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect(Auth::check() ? '/dashboard' : '/login');
});

Route::livewire('/login', 'auth.login')
    ->middleware('guest')
    ->name('login');

Route::livewire('/dashboard', 'dashboard')
    ->middleware('auth')
    ->name('dashboard');

Route::post('/logout', function () {
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect('/login');
})
    ->middleware('auth')
    ->name('logout');