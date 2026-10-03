<?php

use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect(Auth::check() ? '/dashboard' : '/login');
});

Route::livewire('/login', 'auth.login')
    ->middleware('guest')
    ->name('login');

Route::livewire('/register', 'auth.register')
    ->middleware('guest')
    ->name('register');

Route::livewire('/dashboard', 'dashboard')
    ->middleware(['auth', 'password.current'])
    ->name('dashboard');

Route::livewire('/change-password', 'auth.change-password')
    ->middleware('auth')
    ->name('change-password');

Route::livewire('/email/verify', 'auth.verify-email')
    ->middleware('auth')
    ->name('verification.notice');

Route::get('/email/verify/{id}/{hash}', function (EmailVerificationRequest $request) {
    $request->fulfill();

    return redirect('/dashboard');
})->middleware(['auth', 'signed'])->name('verification.verify');

Route::post('/email/verification-notification', function (Request $request) {
    try {
        $request->user()->sendEmailVerificationNotification();
    } catch (\Throwable $e) {
        Log::warning('Verification email failed to send', [
            'to' => $request->user()->email,
            'error' => $e->getMessage(),
        ]);
    }

    return back()->with('status', 'A new verification link has been sent to your email address.');
})->middleware(['auth', 'throttle:6,1'])->name('verification.send');

Route::livewire('/requests', 'requests')
    ->middleware(['auth', 'password.current'])
    ->name('requests');

Route::livewire('/requests/create', 'requests.create')
    ->middleware(['auth', 'password.current'])
    ->name('requests.create');

Route::livewire('/requests/{request}', 'requests.show')
    ->middleware(['auth', 'password.current'])
    ->name('requests.show');

Route::livewire('/jobs', 'jobs')
    ->middleware(['auth', 'password.current'])
    ->name('jobs');

Route::livewire('/jobs/{job}', 'jobs.show')
    ->middleware(['auth', 'password.current'])
    ->name('jobs.show');

Route::livewire('/jobs/{job}/report', 'jobs.report')
    ->middleware(['auth', 'password.current'])
    ->name('jobs.report');

Route::livewire('/documents', 'documents')
    ->middleware(['auth', 'password.current'])
    ->name('documents');

Route::livewire('/documents/{document}', 'documents.show')
    ->middleware(['auth', 'password.current'])
    ->name('documents.show');

Route::livewire('/invoices', 'invoices')
    ->middleware(['auth', 'password.current'])
    ->name('invoices');

Route::livewire('/invoices/create/{job}', 'invoices.create')
    ->middleware(['auth', 'password.current'])
    ->name('invoices.create');

Route::livewire('/invoices/{invoice}', 'invoices.show')
    ->middleware(['auth', 'password.current'])
    ->name('invoices.show');

Route::livewire('/quotations', 'quotations')
    ->middleware(['auth', 'password.current'])
    ->name('quotations');

Route::livewire('/quotations/create', 'quotations.create')
    ->middleware(['auth', 'password.current'])
    ->name('quotations.create');

Route::livewire('/quotations/{quotation}', 'quotations.show')
    ->middleware(['auth', 'password.current'])
    ->name('quotations.show');

Route::livewire('/contracts', 'contracts')->middleware(['auth', 'password.current'])->name('contracts.index');
Route::livewire('/contracts/create', 'contracts.create')->middleware(['auth', 'password.current'])->name('contracts.create');
Route::livewire('/contracts/{contract}', 'contracts.show')->middleware(['auth', 'password.current'])->name('contracts.show');

Route::livewire('/users', 'users')
    ->middleware(['auth', 'password.current'])
    ->name('users');

Route::livewire('/users/create', 'users.create')
    ->middleware(['auth', 'password.current'])
    ->name('users.create');

Route::livewire('/users/{account}', 'users.show')
    ->middleware(['auth', 'password.current'])
    ->name('users.show');

Route::post('/logout', function () {
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect('/login');
})
    ->middleware('auth')
    ->name('logout');
