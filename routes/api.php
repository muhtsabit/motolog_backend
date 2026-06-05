<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ServiceController;

Route::post('/login-google', [AuthController::class, 'loginWithGoogle']);
Route::post('/login', [AuthController::class, 'login']);    
Route::post('/register', [AuthController::class, 'register']); 
Route::get('/motorcycles/{user_id}', [App\Http\Controllers\Api\MotorCycleController::class, 'index']);
Route::post('/motorcycles', [App\Http\Controllers\Api\MotorCycleController::class, 'store']);
Route::match(['post', 'patch'], '/motorcycles/{id}/km', [App\Http\Controllers\Api\MotorCycleController::class, 'updateKm']);
Route::post('/services', [ServiceController::class, 'store']);
Route::get('/services/{motorcycle_id}', [ServiceController::class, 'show']);

Route::post('/logout', [App\Http\Controllers\Api\MotorCycleController::class, 'logout']);
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');