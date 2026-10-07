<?php

use App\Http\Controllers\KvValueController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Key-value store
|--------------------------------------------------------------------------
| The "data/" and "history/" segments separate key-bearing paths from literal
| ones. No literal path can shadow a key, so there are no reserved key names
| and no key is ever unreachable. A key named "get-all-keys" works normally.
*/
Route::prefix('kv-value')->group(function () {
    Route::post('/data', [KvValueController::class, 'store']);
    Route::post('/bulk-create', [KvValueController::class, 'bulkStore']);
    Route::get('/get-all-keys', [KvValueController::class, 'allKeys']);

    Route::get('/data/{key}', [KvValueController::class, 'show'])
        ->where('key', config('kv.key_pattern'));

    Route::get('/history/{key}', [KvValueController::class, 'history'])
        ->where('key', config('kv.key_pattern'));
});

/*
|--------------------------------------------------------------------------
| Health
|--------------------------------------------------------------------------
| The deploy smoke test and the container HEALTHCHECK both call this. It
| answers 503 when a dependency is down, so a half-broken container does not
| report itself healthy.
*/
Route::get('/health', function () {
    $checks = [];

    try {
        DB::select('select 1');
        $checks['database'] = 'ok';
    } catch (Throwable) {
        $checks['database'] = 'fail';
    }

    try {
        Redis::connection()->ping();
        $checks['redis'] = 'ok';
    } catch (Throwable) {
        $checks['redis'] = 'fail';
    }

    $healthy = ! in_array('fail', $checks, true);

    return response()->json([
        'status' => $healthy ? 'ok' : 'degraded',
        'checks' => $checks,
    ], $healthy ? 200 : 503);
})->name('health')->withoutMiddleware('throttle:api');

/*
|--------------------------------------------------------------------------
| Documentation
|--------------------------------------------------------------------------
| Swagger UI over a hand-written public/openapi.yaml. No annotation scanner:
| seven routes do not justify one.
*/
Route::view('/docs', 'docs')->name('docs');

Route::redirect('/', '/docs');
