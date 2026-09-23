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

Route::livewire('/requests', 'requests')
    ->middleware('auth')
    ->name('requests');

Route::livewire('/requests/{request}', 'requests.show')
    ->middleware('auth')
    ->name('requests.show');

Route::livewire('/jobs', 'jobs')
    ->middleware('auth')
    ->name('jobs');

Route::livewire('/jobs/{job}', 'jobs.show')
    ->middleware('auth')
    ->name('jobs.show');

Route::livewire('/jobs/{job}/report', 'jobs.report')
    ->middleware('auth')
    ->name('jobs.report');

Route::livewire('/documents', 'documents')
    ->middleware('auth')
    ->name('documents');

Route::livewire('/documents/{document}', 'documents.show')
    ->middleware('auth')
    ->name('documents.show');

Route::post('/logout', function () {
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect('/login');
})
    ->middleware('auth')
    ->name('logout');