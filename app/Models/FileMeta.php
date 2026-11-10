<?php

namespace App\Models;


use MongoDB\Laravel\Eloquent\Model;

class FileMeta extends Model
{
    protected $connection = 'mongodb';
    protected $table = 'file';

    protected $fillable = [
        'name', 'parent_id', 'owner_id', 'ext', 'size',
        'status', 'shares', 'processed_at',
    ];
    protected $casts = [
        'owner_id'     => 'integer',
        'size'         => 'integer',
        'processed_at' => 'datetime',
    ];
}
