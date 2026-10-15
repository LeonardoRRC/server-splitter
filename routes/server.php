<?php

use Illuminate\Support\Facades\Route;
use UK\TheQuantum\ServerSplitter\Http\Controllers\SplitController;

// /api/client/servers/{server}/extensions/server-splitter/...
Route::get('/', [SplitController::class, 'index']);
Route::get('/types', [SplitController::class, 'types']);
Route::get('/versions', [SplitController::class, 'versions']);
Route::get('/builds', [SplitController::class, 'builds']);
Route::post('/', [SplitController::class, 'store']);
Route::put('/type', [SplitController::class, 'changeType']);
Route::patch('/{child}/resources', [SplitController::class, 'resize']);
Route::put('/{child}/type', [SplitController::class, 'changeType']);
Route::post('/{child}/promote', [SplitController::class, 'promote']);
Route::delete('/{child}', [SplitController::class, 'destroy']);
