<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

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
        $donekey = "processed:{$this->fileId}";

        if(Redis::exists($donekey)){
            Log::info("File {$this->fileId} has already been processed. Skipping.");
            return;
        }

        Log::info("Processing file {$this->fileId}...");
        sleep(20); // Simulate processing time
        Redis::set($donekey, now()->toIso8601String());
        Log::info("File {$this->fileId} processed successfully33333.");
    }
}
