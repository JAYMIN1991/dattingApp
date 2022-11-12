<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});

Route::post('app-login', 'Controller@login');
Route::post('logout', 'Controller@login');
Route::post('register', 'Controller@register');
Route::get('remove-user', 'Controller@removeUserProfile');
Route::post('update-user', 'Controller@updateUserProfile');
Route::get('remove-image', 'Controller@removeImage');
Route::get('image-list', 'Controller@getImagesList');

Route::post('update-profile-picture', 'Controller@updateUserPicture');
Route::post('insert-user-images', 'Controller@insertUserImages');
Route::post('random-list', 'Controller@getRandomUsers');
Route::post('get-near-users', 'Controller@getNearUserList');
Route::post('get-location', 'Controller@getUserLocation');
Route::post('get-user-details', 'Controller@getUserDetails');
Route::post('get-match-users', 'Controller@getMatchUser');
Route::post('user-like', 'Controller@like');
Route::post('get-liked-user', 'Controller@getLikedUser');
Route::post('get-likes-user', 'Controller@getLikesUser');
Route::post('user-dislike', 'Controller@dislike');

Route::post('send_message', 'Controller@send_message');
Route::post('chat_users_list', 'Controller@chat_users_list');
Route::post('user_chats_list', 'Controller@user_chats_list');
