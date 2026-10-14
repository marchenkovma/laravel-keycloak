<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'home')->name('home');

// Проверка keycloak.role: user получит 403 с текстом из переводов пакета
Route::view('admin', 'admin')->middleware(['auth', 'keycloak.role:admin'])->name('admin');
