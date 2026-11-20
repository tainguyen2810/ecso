<?php

use App\Modules\Identity\Http\Controllers\EzChatSsoController;
use App\Modules\Platform\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'live']);
Route::get('/health/ready', [HealthController::class, 'ready']);

// Đợt 17 "Đăng nhập bằng EzChat" (session login, so these are web routes).
Route::get('/sso/ezchat/start', [EzChatSsoController::class, 'start'])->middleware('throttle:30,1');
Route::get('/sso/ezchat', [EzChatSsoController::class, 'callback'])->middleware('throttle:30,1');

Route::view('/admin/{any?}', 'admin')->where('any', '.*');

Route::view('/{any?}', 'app')->where('any', '^(?!api/|horizon|webhooks/|health|sso/ezchat|install(/|$)|up$).*$');
