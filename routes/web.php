<?php

use App\Http\Controllers\InvitationController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

// The local edition's first-run screen (Windows install, no accounts yet); Login sends people there
Route::get('/setup', [\App\Http\Controllers\SetupController::class, 'show'])->name('setup');
Route::post('/setup', [\App\Http\Controllers\SetupController::class, 'store'])->middleware('throttle:10,1')
    ->name('setup.store');

// Invitations to join a clinic (the emailed link)
Route::get('/invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
Route::post('/invitations/{token}/answer', [InvitationController::class, 'answer'])->middleware(['auth', 'throttle:10,1'])
    ->name('invitations.answer');
Route::post('/invitations/{token}/register', [InvitationController::class, 'register'])->middleware(['guest', 'throttle:5,1'])
    ->name('invitations.register');

// An issued prescription's PDF: signed-in clinic members only (row-level security decides which)
Route::get('/prescriptions/{prescription}/pdf', \App\Http\Controllers\PrescriptionPdfController::class)
    ->whereNumber('prescription')->middleware(['auth', 'throttle:30,1'])
    ->name('prescriptions.pdf');
