<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ReportController;

Route::middleware(['web', \Filament\Http\Middleware\Authenticate::class])
    ->get('/reports/{entity}/return/{year}', [ReportController::class, 'schedule'])
    ->where('year', '\d{4}')
    ->name('reports.return');

Route::middleware(['web', \Filament\Http\Middleware\Authenticate::class])
    ->get('/reports/{entity}/{period}', [ReportController::class, 'statement'])
    ->where('period', '^(\d{4}-\d{2}|year-\d{4})$')
    ->name('reports.statement');

Route::middleware(['web', \Filament\Http\Middleware\Authenticate::class])->group(function () {
    Route::get('/reports/{entity}/assessments/{period}', [ReportController::class, 'assessmentSummary'])
        ->where('period', '^\d{4}-\d{2}$')
        ->name('reports.assessments');

    Route::get('/reports/{entity}/assessments/{period}/{society}', [ReportController::class, 'assessmentStatement'])
        ->where('period', '^\d{4}-\d{2}$')
        ->where('society', '[A-Za-z0-9_-]+')
        ->name('reports.assessments.society');
});