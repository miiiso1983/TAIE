<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class RuntimeHeartbeat implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('critical');
    }

    public function handle(): void
    {
        Cache::put('teami.worker_heartbeat', now()->timestamp, 300);
    }
}
