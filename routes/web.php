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

Route::livewire('/kategoriler', 'pages::catalog.categories')
    ->middleware(['auth', 'can:category_management.viewAny'])
    ->name('categories.index');

Route::livewire('/tedarikciler', 'pages::catalog.suppliers')
    ->middleware(['auth', 'can:supplier_management.viewAny'])
    ->name('suppliers.index');

Route::livewire('/urunler', 'pages::catalog.products')
    ->middleware(['auth', 'can:product_management.viewAny'])
    ->name('products.index');
