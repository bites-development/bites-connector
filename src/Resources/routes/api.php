<?php

use Modules\BitesMiddleware\Controllers\PlanWebhookController;
use Modules\BitesMiddleware\Controllers\SnsController;
use Illuminate\Support\Facades\Route;

Route::post('sns-listener', [SnsController::class, 'listener']);
Route::post('webhooks/plan-changed', [PlanWebhookController::class, 'handlePlanChanged']);
