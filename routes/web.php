<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MemoryController;
use App\Http\Controllers\SubAgentController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('memories', [MemoryController::class, 'index'])->name('memories.index');
    Route::delete('memories/{memory}', [MemoryController::class, 'destroy'])->name('memories.destroy');

    Route::get('subagents', [SubAgentController::class, 'index'])->name('subagents.index');
    Route::post('subagents', [SubAgentController::class, 'store'])->name('subagents.store');
    Route::patch('subagents/{subAgent}', [SubAgentController::class, 'update'])->name('subagents.update');
    Route::delete('subagents/{subAgent}', [SubAgentController::class, 'destroy'])->name('subagents.destroy');
});

require __DIR__.'/settings.php';
