<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->to(auth()->check() ? '/dashboard' : '/login');
});

Route::livewire('/login', 'pages::access.login')
    ->middleware('guest')
    ->name('login');

Route::livewire('/dashboard', 'pages::dashboard')
    ->middleware('auth')
    ->name('dashboard');

Route::livewire('/personel', 'pages::access.staff')
    ->middleware(['auth', 'can:staff_management.viewAny'])
    ->name('staff.index');
