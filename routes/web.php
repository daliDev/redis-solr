<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Request;
use App\Jobs\ProcessFile;
use Illuminate\Support\Facades\Redis;
use App\Models\FileMeta;
use MongoDB\BSON\UTCDateTime;

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



Route::get('/upload/{name}', function (string $name) {
    $file = FileMeta::create([
        'name' => $name,
        'parent_id' => 'folder-1',
        'owner_id' => 1,
        'ext' => pathinfo($name, PATHINFO_EXTENSION),
        'size' => random_int(1000, 1000000),
        'shares' => [],
        'status' => 'queued',

    ]);
    ProcessFile::dispatch($file->id);
    return ['id' => $file->id, 'status' => $file->status];
});

Route::get('/files/{id}',fn (string $id) => FileMeta::findOrFail($id));

Route::get('/mongo/seed', function () {
     FileMeta::truncate();
     $exts = ['pdf', 'docx', 'xlsx', 'txt'];
    for ($b = 0; $b < 20; $b++) {
        $batch = [];
        for ($i = 0; $i < 1000; $i++) {
            $n = $b * 1000 + $i;
            $ext = $exts[array_rand($exts)];
            $batch[] = [
                'name'       => "doc-{$n}.{$ext}",
                'parent_id'  => 'folder-' . random_int(1, 500),
                'owner_id'   => random_int(1, 50),
                'ext'        => $ext,
                'size'       => random_int(1_000, 50_000_000),
                'status'     => 'processed',
                'shares'     => random_int(0, 9) === 0 ? [['user_id' => random_int(1, 50), 'perm' => 'read']] : [],
                'created_at' => new UTCDateTime(now()->subMinutes(random_int(0, 525_600))),
            ];
        }
        FileMeta::insert($batch);
    }
    return ['files' => FileMeta::count()];
});

Route::get('/files/owner/{ownerId}', function (int $ownerId) {
    $t = microtime(true);
    $files = FileMeta::where('owner_id', $ownerId)
    ->orderBy('created_at', 'desc')
    ->limit(10)
    ->get();
    return ['ms' => round((microtime(true) - $t) * 1000), 'files' => $files];
});

Route::get('/files/{id}/share/{user}', function (string $id, int $user) {
    // أضف فقط لو المستخدم غير موجود — شرط وتحديث في عملية ذرّية واحدة
    $added = FileMeta::where('_id', $id)
        ->where('shares.user_id', '!=', $user)
        ->push('shares', ['user_id' => $user, 'perm' => 'read']);

    return ['added' => (bool) $added, 'file' => FileMeta::find($id, ['name', 'shares'])];
});

Route::get('/files/{id}/unshare/{user}',function(string $id,string $user){
    $file = FileMeta::where('id', $id)->pull('shares',['user_id'=>$user]);
    return $file->find($id,['name','shares']);
});
Route::get('/files/shared-with/{user}',function(int $user){
    $files = FileMeta::where('shares.user_id',$user)->get(['name','owner_id']);
    return ['count'=>count($files),'files'=>$files];
});


Route::get('/files/stats/{owner}',function(int $owner){
   $row = FileMeta::raw(fn ($collection) => $collection->aggregate([
        ['$match'=>['owner_id'=>$owner]],
        ['$group'=>['_id'=>'$ext','count'=>['$sum'=>1],'total_size'=>['$sum'=>['$divide'=>['$size', 1048576]]]]],
        ['$sort'=>['total_size'=>-1]],
        ['typeMap'=>['root'=>'array','document'=>'array']]
    ]));

    return iterator_to_array($row);
});
   