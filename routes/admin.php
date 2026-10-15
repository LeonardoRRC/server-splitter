<?php

use Illuminate\Support\Facades\Route;
use UK\TheQuantum\ServerSplitter\Http\Controllers\AdminController;

// /api/admin/extensions/server-splitter/...
Route::get('/config', [AdminController::class, 'show']);
Route::put('/config', [AdminController::class, 'update']);
