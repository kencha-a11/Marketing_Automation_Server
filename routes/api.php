<?php

use App\Http\Controllers\Api\ElevateAutomationController;
use Illuminate\Support\Facades\Route;

Route::get('/reg-status/{gcashRef}', [ElevateAutomationController::class, 'getStatus']);
Route::post('/automate-registration', [ElevateAutomationController::class, 'automateRegistration']);
Route::post('/gcash-webhook', [ElevateAutomationController::class, 'handleGCashWebhook']);
Route::post('/parse-ocr', [ElevateAutomationController::class, 'parseReceiptOCR']);

// Health check
Route::get('/health', function () {
    return response()->json(['status' => 'ok', 'timestamp' => now()]);
});
