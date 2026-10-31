<?php

use App\Modules\Platform\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'live']);
Route::get('/health/ready', [HealthController::class, 'ready']);

Route::view('/admin/{any?}', 'admin')->where('any', '.*');

Route::view('/{any?}', 'app')->where('any', '^(?!api/|horizon|webhooks/|health|up$).*$');
