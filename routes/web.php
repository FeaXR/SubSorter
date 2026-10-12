<?php

use App\Http\Controllers\ChannelController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GoogleAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

Route::get('/auth/google', [GoogleAuthController::class, 'redirect'])->name('auth.google');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');
Route::post('/auth/logout', [GoogleAuthController::class, 'logout'])->name('auth.logout');

Route::post('/subscriptions/refresh', [DashboardController::class, 'refreshSubscriptions'])->name('subscriptions.refresh');
Route::post('/playlists/refresh', [DashboardController::class, 'refreshPlaylists'])->name('playlists.refresh');

Route::get('/channels', [ChannelController::class, 'index'])->name('channels.index');
Route::post('/channels/default', [ChannelController::class, 'updateDefault'])->name('channels.default');
Route::patch('/channels/{subscription}', [ChannelController::class, 'update'])->name('channels.update');

Route::post('/videos/load', [DashboardController::class, 'loadVideos'])->name('videos.load');
Route::post('/videos/accept', [DashboardController::class, 'accept'])->name('videos.accept');
Route::post('/videos/discard', [DashboardController::class, 'discard'])->name('videos.discard');
Route::delete('/videos/{pendingVideo}', [DashboardController::class, 'removePending'])->name('videos.remove');

Route::post('/last-request', [DashboardController::class, 'setLastRequest'])->name('last-request.update');
