<?php

use App\Http\Controllers\AdminController;

use App\Http\Controllers\AuthController;

use App\Http\Controllers\UserPostController;
use App\Http\Middleware\UserMiddleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;






Route::controller(AuthController::class)->group(function(){
//Authcontroller routes 
Route::post('login','login');
Route::post('register','register');
Route::post('forgot/password','forgot');
Route::post('reset/password','reset');
Route::get('reset-password-link/{token}', 'resetPasswordLink')->name('reset');
Route::get('get/user', 'getUser');


Route::get('remember/{id}/me','remember');

});





Route::middleware(['auth:sanctum'])->group(function(){

Route::post('/logout', [AuthController::class, 'logout']); //logout
Route::put('/', [AuthController::class, 'updateProfile']); //logout

});








Route::middleware('auth:sanctum','is_admin')->group(function(){
//admin routes
Route::get('admin/users',[AdminController::class,'users']);
Route::get('admin/{status?}', [AdminController::class, 'getPosts'])
    ->where('status', 'unverified|verified');
Route::get('admin/failed',[AdminController::class,'failed']);
Route::put('admin/{id}/accept',[AdminController::class,'accept']);
Route::put('admin/{id}/reject',[AdminController::class,'reject']);
Route::delete('admin/{id}/delete',[AdminController::class,'delete']);
Route::get('admin/images/{id}', [AdminController::class, 'getImage']);


 


});









Route::middleware('auth:sanctum','is_user')->group(function(){
  //intern routes  
  Route::post('mypost',[UserPostController::class,'store']);


});

