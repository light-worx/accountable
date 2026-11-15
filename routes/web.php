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