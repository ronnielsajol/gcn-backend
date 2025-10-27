<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\UserFileController;
use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\StatsController;
use Illuminate\Support\Facades\Route;

// Authentication routes
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/debug/users-with-event-count-json', [UserController::class, 'testUsersWithEventCountJson']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // User management routes
    // Note: These routes support ?event_id=X query parameter to include is_event_attendee attribute
    // Example: GET /api/users?event_id=1 or GET /api/users/5?event_id=1
    Route::apiResource('users', UserController::class);
    Route::get('/users/{user}/events', [UserController::class, 'getUserEvents']);

    Route::post('users/{id}', [UserController::class, 'update'])->name('users.update.post');

    Route::apiResource('/admins', AdminController::class);
    Route::post('admins/{id}', [AdminController::class, 'update'])->name('admins.update.post');

    Route::apiResource('/events', EventController::class);
    Route::get('/events/{event}/users', [EventController::class, 'getEventUsers']);

    // Export event attendees (add this to your EventController routes)
    Route::get('/events/{event}/export/csv/attendees', [EventController::class, 'exportEventAttendees']);
    Route::get('/events/{event}/export/pdf/attendees', [EventController::class, 'exportEventAttendeesPdf']);


    Route::patch('events/{event}/status', [EventController::class, 'updateStatus']);

    Route::post('/events/{event}/users', [EventController::class, 'attachUser']);
    Route::delete('/events/{event}/users', [EventController::class, 'detachUser']);


    // User file routes - Frontend specific endpoints
    Route::post('/users/{userId}/files/upload', [UserFileController::class, 'upload']);
    Route::delete('/users/{userId}/files/bulk-delete', [UserFileController::class, 'bulkDelete']);

    // User file routes - Original/backward compatibility endpoints
    Route::post('/users/{userId}/files/bulk', [UserFileController::class, 'bulkStore']);
    Route::delete('/users/{userId}/files/bulk', [UserFileController::class, 'bulkDestroy']);

    // Standard user file routes
    Route::get('/users/{userId}/files', [UserFileController::class, 'index']);
    Route::post('/users/{userId}/files', [UserFileController::class, 'store']);
    Route::delete('/users/{userId}/files/{fileId}', [UserFileController::class, 'destroy']);
    Route::get('/users/{userId}/files/{fileId}/download', [UserFileController::class, 'download']);

    // Activity logs (view only)
    Route::get('/activity-logs', [ActivityLogController::class, 'index']);

    // Export all users with event count
    Route::get('/users/export/csv/with-event-count', [UserController::class, 'exportUsersWithEventCount'])
        ->name('users.export.csv.event-count');

    // Export specific user's detailed info
    Route::get('/users/{id}/export/csv', [UserController::class, 'exportUserInfo'])
        ->name('users.export.csv.info');

    // Stats routes
    Route::get('/stats/events/{event}/sphere-stats', [StatsController::class, 'getSphereStatsPerEvent'])->name('stats.events.spheres');
});

// Apply role-based restrictions
Route::middleware(['auth:sanctum', 'role:super_admin'])->group(function () {
    Route::delete('/users/{id}', [UserController::class, 'destroy']);
});
