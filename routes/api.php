<?php

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\GenerateApiController;
use App\Http\Controllers\Api\MediaApiController;
use App\Http\Controllers\Api\PersonaApiController;
use App\Http\Controllers\Api\PostApiController;
use App\Http\Controllers\Api\StatsApiController;
use App\Http\Controllers\Api\ThreadApiController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    // ── Auth ──
    Route::get('/me', [ApiController::class, 'me']);
    Route::get('/accounts', [ApiController::class, 'accounts']);

    // ── Posts ──
    Route::get('/posts', [PostApiController::class, 'index']);
    Route::post('/posts', [PostApiController::class, 'store']);
    Route::get('/posts/{post}', [PostApiController::class, 'show']);
    Route::put('/posts/{post}', [PostApiController::class, 'update']);
    Route::delete('/posts/{post}', [PostApiController::class, 'destroy']);
    Route::post('/posts/{post}/publish', [PostApiController::class, 'publish']);
    Route::post('/bulk-schedule', [ApiController::class, 'bulkSchedule']);
    Route::post('/bulk-cancel', [PostApiController::class, 'bulkCancel']);

    // ── Threads ──
    Route::get('/threads', [ThreadApiController::class, 'index']);
    Route::post('/threads', [ThreadApiController::class, 'store']);
    Route::get('/threads/{thread}', [ThreadApiController::class, 'show']);
    Route::put('/threads/{thread}', [ThreadApiController::class, 'update']);
    Route::delete('/threads/{thread}', [ThreadApiController::class, 'destroy']);
    Route::post('/threads/{thread}/publish', [ThreadApiController::class, 'publish']);
    Route::post('/bulk-schedule-threads', [ApiController::class, 'bulkScheduleThreads']);

    // ── Personas ──
    Route::get('/personas', [PersonaApiController::class, 'index']);
    Route::post('/personas', [PersonaApiController::class, 'store']);
    Route::get('/personas/{persona}', [PersonaApiController::class, 'show']);
    Route::put('/personas/{persona}', [PersonaApiController::class, 'update']);
    Route::delete('/personas/{persona}', [PersonaApiController::class, 'destroy']);

    // ── Stats ──
    Route::get('/stats/overview', [StatsApiController::class, 'overview']);
    Route::get('/stats/audience', [StatsApiController::class, 'audience']);
    Route::get('/stats/top-posts', [StatsApiController::class, 'topPosts']);
    Route::get('/stats/platforms', [StatsApiController::class, 'platforms']);
    Route::get('/calendar', [StatsApiController::class, 'calendar']);

    // ── Génération IA (preview) ──
    Route::post('/generate', [GenerateApiController::class, 'generate']);
    Route::post('/generate-thread', [GenerateApiController::class, 'generateThread']);

    // ── Catalogue média (pipeline Mac + recherche sémantique) ──
    Route::post('/media/ingest', [MediaApiController::class, 'ingest']);
    Route::get('/media/search', [MediaApiController::class, 'search']);
    Route::get('/media/pending-analysis', [MediaApiController::class, 'pendingAnalysis']);
    Route::post('/media/{media}/validate', [MediaApiController::class, 'validateMedia']);
    Route::post('/media/{media}/enrich', [MediaApiController::class, 'enrich']);
    Route::post('/media/{media}/mark-published', [MediaApiController::class, 'markPublished']);

    // ── Banques d'images externes (Pexels, Pixabay, Unsplash) ──
    Route::get('/stock-photos/search', [MediaApiController::class, 'stockPhotosSearch']);
});
