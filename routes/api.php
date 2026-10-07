<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserPostController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;


// Healthcheck for Docker: 200 when the app can reach the database, 503 otherwise
Route::get('health', function () {
    try {
        DB::connection()->getPdo();
        return response()->json(['status' => 'ok']);
    } catch (\Throwable $e) {
        return response()->json(['status' => 'error', 'db' => $e->getMessage()], 503);
    }
});


// Public auth routes
Route::controller(AuthController::class)->group(function () {
    Route::post('login', 'login');
    Route::post('register', 'register');
    Route::post('forgot/password', 'forgot');
    Route::post('reset/password', 'reset');
});


// Any signed-in user
Route::middleware('auth:sanctum')->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::put('/', [AuthController::class, 'updateProfile']); // update phone / avatar
});


// Officers (role 1)
Route::middleware(['auth:sanctum', 'is_admin'])->group(function () {
    Route::get('admin/users', [AdminController::class, 'users']);
    Route::get('admin/{status?}', [AdminController::class, 'getPosts'])
        ->where('status', 'unverified|verified');
    Route::put('admin/{id}/accept', [AdminController::class, 'accept']);
    Route::put('admin/{id}/reject', [AdminController::class, 'reject']);
    Route::delete('admin/{id}/delete', [AdminController::class, 'delete']);
});


// Farmers (role 0)
Route::middleware(['auth:sanctum', 'is_user'])->group(function () {
    Route::post('mypost', [UserPostController::class, 'store']);
});
