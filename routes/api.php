<?php

use App\Http\Controllers\Api\AutomationController;
use Illuminate\Support\Facades\Route;

Route::get('/reg-status/{gcashRef}', [AutomationController::class, 'getStatus']);

Route::post('/automate-registration', [AutomationController::class, 'automateRegistration']);

// GCash Webhook Endpoint
Route::post('/gcash-webhook', [AutomationController::class, 'handleGCashWebhook']);

Route::post('/parse-ocr', [AutomationController::class, 'parseReceiptOCR']);