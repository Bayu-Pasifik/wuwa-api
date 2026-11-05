<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

use App\Http\Controllers\WikiEntryController;

Route::get('{type}', [WikiEntryController::class, 'index']);
Route::get('{type}/{slug}', [WikiEntryController::class, 'show'])->where('slug', '.*');
