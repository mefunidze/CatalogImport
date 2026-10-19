<?php

use App\Http\Controllers\ImportController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'imports');
Route::get('/sample.csv', fn () => response()->download(base_path('examples/products.csv'), 'products.csv'));
Route::prefix('api')->group(function () {
    Route::get('/imports', [ImportController::class, 'index']);
    Route::post('/imports', [ImportController::class, 'store']);
    Route::get('/imports/{import}', [ImportController::class, 'show']);
    Route::post('/imports/{import}/retry', [ImportController::class, 'retry']);
    Route::get('/imports/{import}/errors', [ImportController::class, 'errors']);
    Route::get('/products', [ImportController::class, 'products']);
});
