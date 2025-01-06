<?php

use Modules\BitesMiddleware\Controllers\SnsController;
use Illuminate\Support\Facades\Route;

Route::post('sns-listener', [SnsController::class, 'listener']);
