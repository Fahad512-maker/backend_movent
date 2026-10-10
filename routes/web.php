<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// This app is API-only — no real login page exists. Laravel's Authenticate
// middleware/exception handler resolves a redirect target by name
// ('login') for any unauthenticated request that doesn't ask for JSON
// (e.g. a bare browser hit with no Accept header); without this route,
// that resolution itself throws RouteNotFoundException and turns what
// should be a 401 into a 500. Named so route('login') always resolves.
Route::get('/login', function () {
    return response()->json(['message' => 'Unauthenticated.'], 401);
})->name('login');
