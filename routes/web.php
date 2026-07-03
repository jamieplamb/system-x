<?php

use App\Http\Controllers\Auth\LoginController;
use App\Support\RememberedUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Route;
use SystemX\Core\Preferences\PreferencesService;
use SystemX\Core\State\StateKey;
use SystemX\Core\Support\Desktop;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'authenticate'])
        ->middleware('throttle:login');
});

Route::post('/logout', [LoginController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

Route::get('/', function (Request $request, Desktop $desktop) {
    // The host now CONSUMES the package's served desktop (packaging plan, Task 10). The whole
    // seed-once + boot-data assembly + view lives in Desktop::render(), which renders the
    // package's system-x::desktop off the served dist bundle (the @systemxStyles/@systemxScripts
    // directives + window.sxConfig), NOT the host's old @vite blade.
    //
    // The cosmetic remember-cookie stays consumer-owned (auth lane). Desktop::render() is
    // auth-agnostic -- it just reads $request->user() -- so the host wraps it with the cookie
    // queue + delegates the whole boot-data assembly + view to the package. It carries the
    // user's LATEST look + name + email so the greeter can reskin + greet them next time;
    // EncryptCookies encrypts it on the way out and it holds no id/token (cosmetic only, D3).
    $prefs = app(PreferencesService::class)->forPrincipal(new StateKey('user', (string) $request->user()->id, ''));
    Cookie::queue(RememberedUser::cookie($request->user(), $prefs));

    return $desktop->render($request);
})->middleware('auth');
