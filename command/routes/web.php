<?php

use App\Http\Controllers\Web\SessionController;
use Illuminate\Support\Facades\Route;

// Command-room sign-in (session cookie). The Vue app then calls /api/v1 with that cookie.
Route::post('session', [SessionController::class, 'store'])->middleware('throttle:10,1')->name('login');
Route::post('session/two-factor', [SessionController::class, 'twoFactor'])->middleware('throttle:10,1')->name('login.two-factor');
Route::delete('session', [SessionController::class, 'destroy'])->name('logout');

// Every other web path is a screen in the Vue command-room app.
Route::view('/{any?}', 'command')->where('any', '^(?!api|sanctum|up|storage).*$')->name('command');
