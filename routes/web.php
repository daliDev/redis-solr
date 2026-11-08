<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Request;
use App\Jobs\ProcessFile;
use Illuminate\Support\Facades\Redis;


Route::get('/', function () {
    return view('welcome');
});



Route::get('/cache/{uid}', function (string $uid) {
    $t = microtime(true);
    $hit = Cache::has("quota:{$uid}");
    $quota = Cache::remember("quota:{$uid}", 60, function () {
        sleep(2); // استعلام ثقيل على القاعدة
        return ['used_mb' => random_int(100, 900), 'limit_mb' => 1024];
    });
    return [
        'source' => $hit ? 'cache HIT' : 'cache MISS',
        'ms'     => round((microtime(true) - $t) * 1000),
        'quota'  => $quota,
    ];
});

Route::get('/cache/{uid}/forget', function (string $uid) {
    Cache::forget("quota:{$uid}"); // إبطال صريح عند الكتابة
    return ['invalidated' => "quota:{$uid}"];
});

// B) Session في Redis — الحاوية stateless
Route::get('/session', function (Request $r) {
    $n = $r->session()->increment('visits');
    return ['visits' => $n, 'session_id' => $r->session()->getId()];
});

// C) Distributed lock — طلبان متزامنان على نفس الملف
Route::get('/lock/{fileId}', function (string $fileId) {
    $lock = Cache::lock("index:{$fileId}", 30); // TTL يحمي من قفل أبدي لو ماتت العملية
    if (! $lock->get()) {
        return response(['status' => 'busy — another process holds the lock'], 423);
    }
    try {
        sleep(5);
        return ['status' => "indexed {$fileId}"];
    } finally {
        $lock->release();
    }
});

// D) Rate limiting — 5 طلبات في الدقيقة لكل IP
Route::get('/rate', function (Request $r) {
    $key = 'download:' . $r->ip();
    if (! RateLimiter::attempt($key, 5, fn () => true, 50)) {
        return response(['retry_after_s' => RateLimiter::availableIn($key)], 429);
    }
    return ['remaining' => RateLimiter::remaining($key, 5)];
});

// E) Idempotency — نفس نمط callbacks بوابات الدفع
Route::post('/pay', function (Request $r) {
    $key = $r->header('Idempotency-Key');
    abort_if(! $key, 400, 'Idempotency-Key header required');

    // SET key 1 EX 86400 NX — ذرّي: أول طلب فقط ينجح
    $first = Redis::set("idem:{$key}", 1, 'EX', 86400, 'NX');
    if (! $first) {
        return response(['status' => 'duplicate — ignored'], 409);
    }
    return response(['status' => 'payment recorded'], 201);
});

// F) Queue + ShouldBeUnique
Route::get('/upload/{fileId}', function (string $fileId) {
    ProcessFile::dispatch($fileId);
    return ['queued' => $fileId];
});