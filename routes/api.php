<?php

use App\Http\Controllers\API\PayController;
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

Route::middleware(['auth:sanctum','role:director|supervisor'])->group( function () {
    Route::prefix('pays')->controller(PayController::class)->group(function () {
        Route::get('/', 'index');
        Route::post('/enable-pro', 'enablePro');
        Route::post('/disable-pro', 'disablePro');
        Route::post('/enable-buh', 'enableBuh');
        Route::post('/disable-buh', 'disableBuh');
        Route::post('/enable-seo', 'enableSeo');
        Route::post('/disable-seo', 'disableSeo');
        Route::post('/enable-seoex', 'enableSeoExpress');
        Route::post('/disable-seoex', 'disableSeoExpress');
    });
});