<?php

use App\Http\Controllers\Api\ApiController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [ApiController::class, 'me']);
    Route::get('/accounts', [ApiController::class, 'accounts']);
    Route::get('/personas', [ApiController::class, 'personas']);
    Route::post('/bulk-schedule', [ApiController::class, 'bulkSchedule']);
});
