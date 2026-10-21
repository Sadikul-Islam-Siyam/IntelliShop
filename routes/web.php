<?php

use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\BranchController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
});

// Authentication web routes
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('login.throttle')->name('login.post');
});

// Authenticated dashboard routes
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::middleware('role:admin,branch-manager')->group(function () {
        Route::get('/branches', [BranchController::class, 'index'])->name('branches.index');
    });
    Route::middleware('role:admin')->group(function () {
        Route::get('/branches/create', [BranchController::class, 'create'])->name('branches.create');
        Route::post('/branches', [BranchController::class, 'store'])->name('branches.store');
        Route::get('/branches/{branch}/edit', [BranchController::class, 'edit'])->name('branches.edit');
        Route::put('/branches/{branch}', [BranchController::class, 'update'])->name('branches.update');
        Route::delete('/branches/{branch}', [BranchController::class, 'destroy'])->name('branches.destroy');
    });

    // Dashboard root landing: redirects user to their dedicated role workspace
    Route::get('/dashboard', [\App\Http\Controllers\Web\DashboardController::class, 'index'])->name('dashboard');

    // Role-specific dashboard route protected by role middleware
    Route::get('/dashboard/{role}', [\App\Http\Controllers\Web\DashboardController::class, 'show'])
        ->middleware('role')
        ->name('dashboard.role');

    // System Audit Logs page (Administrator only)
    Route::get('/audit-logs', [\App\Http\Controllers\Web\AuditLogController::class, 'index'])
        ->middleware('role:admin,super-admin')
        ->name('audit-logs.index');
});
