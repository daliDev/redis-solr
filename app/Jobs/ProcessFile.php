<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use App\Models\FileMeta;

class ProcessFile implements ShouldQueue,ShouldBeUnique
{
    use Queueable;


    public int $tries = 3;
    public array $backoff = [5, 30, 60]; // Retry after 10s, 30s, and 60s
    public int $timeout = 60; // Timeout after 120 seconds
    public int $uniqueFor = 300; // Unique for 1 hour

    /**
     * Create a new job instance.
     */
    public function __construct(public string $fileId)
    {
        
    }

    public function uniqueId(): string
    {
        return $this->fileId;
    }
    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $file = FileMeta::find($this->fileId);
        if ($file->status === 'processed') {
            Log::Info("skip {$file->name}: already processed");
            return;
        }
        Log::info("Proccessing file {$file->name} (attempt {$this->attempts()})...");
        sleep(10); // Simulate processing time

        $file->update([
            'status' => 'processed',
            'processed_at' => now(),
        ]);
        Log::info("File {$file->name} done.");
    }
}
