<?php
use App\Http\Controllers\OrderIntegrationController;
use Illuminate\Support\Facades\Route;

Route::prefix('integrations/orders')->group(function () {
    Route::post('preview', [OrderIntegrationController::class, 'preview']);
    Route::post('dispatch', [OrderIntegrationController::class, 'dispatch']);
    Route::get('{orderId}', [OrderIntegrationController::class, 'status']);
});
