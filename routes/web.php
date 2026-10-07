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

Route::livewire('/lpos', 'lpos')
    ->middleware(['auth', 'password.current'])
    ->name('lpos');

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

Route::livewire('/dispatch', 'dispatch')->middleware(['auth', 'password.current'])->name('dispatch');

Route::livewire('/technicians', 'technicians')->middleware(['auth', 'password.current'])->name('technicians.index');
Route::livewire('/technicians/{technician}', 'technicians.show')->middleware(['auth', 'password.current'])->name('technicians.show');
Route::livewire('/equipment', 'equipment')->middleware(['auth', 'password.current'])->name('equipment.index');
Route::livewire('/equipment/create', 'equipment.create')->middleware(['auth', 'password.current'])->name('equipment.create');
Route::livewire('/equipment/{equipment}', 'equipment.show')->middleware(['auth', 'password.current'])->name('equipment.show');
Route::livewire('/equipment/{equipment}/edit', 'equipment.edit')->middleware(['auth', 'password.current'])->name('equipment.edit');
Route::livewire('/inventory', 'inventory')->middleware(['auth', 'password.current'])->name('inventory.index');
Route::livewire('/inventory/create', 'inventory.create')->middleware(['auth', 'password.current'])->name('inventory.create');
Route::livewire('/inventory/{inventoryItem}/edit', 'inventory.edit')->middleware(['auth', 'password.current'])->name('inventory.edit');
Route::livewire('/service-report', 'service-report')->middleware(['auth', 'password.current'])->name('service-report');
Route::livewire('/schedule', 'my-schedule')->middleware(['auth', 'password.current'])->name('schedule');
Route::livewire('/my-reports', 'my-reports')->middleware(['auth', 'password.current'])->name('my-reports');
Route::livewire('/my-equipment', 'my-equipment')->middleware(['auth', 'password.current'])->name('my-equipment');
Route::livewire('/my-equipment/{equipment}', 'my-equipment.show')->middleware(['auth', 'password.current'])->name('my-equipment.show');
Route::livewire('/reports', 'reports')->middleware(['auth', 'password.current'])->name('reports');
Route::livewire('/approvals', 'approvals')->middleware(['auth', 'password.current'])->name('approvals');
Route::livewire('/performance', 'performance')->middleware(['auth', 'password.current'])->name('performance');
Route::livewire('/team-reports', 'team-reports')->middleware(['auth', 'password.current'])->name('team-reports');
Route::livewire('/finance-watch', 'finance-watch')->middleware(['auth', 'password.current'])->name('finance-watch');
Route::livewire('/receipts', 'receipts')->middleware(['auth', 'password.current'])->name('receipts');
Route::livewire('/finance-reports', 'finance-reports')->middleware(['auth', 'password.current'])->name('finance-reports');
Route::livewire('/my-locations', 'my-locations')->middleware(['auth', 'password.current'])->name('my-locations');
Route::livewire('/my-machines', 'my-machines')->middleware(['auth', 'password.current'])->name('my-machines');
Route::livewire('/customer-reports', 'customer-reports')->middleware(['auth', 'password.current'])->name('customer-reports');
Route::livewire('/security', 'security')->middleware(['auth', 'password.current'])->name('security');
Route::livewire('/branches', 'branches')->middleware(['auth', 'password.current'])->name('branches');
Route::livewire('/roles', 'roles')->middleware(['auth', 'password.current'])->name('roles');
Route::livewire('/equipment-categories', 'equipment-categories')->middleware(['auth', 'password.current'])->name('equipment-categories');
Route::livewire('/settings', 'settings')->middleware(['auth', 'password.current'])->name('settings');
Route::livewire('/audit-trail', 'audit-trail')->middleware(['auth', 'password.current'])->name('audit-trail');
Route::livewire('/service-reports', 'service-reports')->middleware(['auth', 'password.current'])->name('service-reports');

Route::livewire('/customers', 'customers')->middleware(['auth', 'password.current'])->name('customers.index');
Route::livewire('/customers/create', 'customers.create')->middleware(['auth', 'password.current'])->name('customers.create');
Route::livewire('/customers/{customer}', 'customers.show')->middleware(['auth', 'password.current'])->name('customers.show');

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
