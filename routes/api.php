<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Request;
use App\Jobs\ProcessFile;
use Illuminate\Support\Facades\Redis;



Route::post('/pay2', function (Request $request) {
    $key = $request->header('Idempotency-Key');
    abort_if(! $key, 400, 'Idempotency-Key header required');

    $first = Redis::set("idem:{$key}", 1, 'EX', 86400, 'NX');

    if (! $first) {
        return response(['status' => 'duplicate — ignored'], 409);
    }

    return response(['status' => 'payment recorded'], 201);
});