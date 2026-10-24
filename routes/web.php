<?php

use App\Http\Controllers\InvitationController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

// Invitations to join a clinic (the emailed link)
Route::get('/invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
Route::post('/invitations/{token}/answer', [InvitationController::class, 'answer'])->middleware(['auth', 'throttle:10,1'])
    ->name('invitations.answer');
Route::post('/invitations/{token}/register', [InvitationController::class, 'register'])->middleware(['guest', 'throttle:5,1'])
    ->name('invitations.register');
