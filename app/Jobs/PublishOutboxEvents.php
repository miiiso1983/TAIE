<?php

namespace App\Jobs;

use App\Models\OutboxEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Throwable;

class PublishOutboxEvents implements ShouldQueue
{
    use Dispatchable, \Illuminate\Queue\InteractsWithQueue, \Illuminate\Queue\SerializesModels, Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public array $backoff = [5, 15, 60, 180];

    public function handle(): void
    {
        $ids = OutboxEvent::query()->whereIn('status', ['pending', 'processing'])->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))->orderBy('id')->limit(50)->pluck('id');
        foreach ($ids as $id) {
            try {
                $event = DB::transaction(function () use ($id) {
                    $row = OutboxEvent::query()->whereKey($id)->whereIn('status', ['pending', 'processing'])->lockForUpdate()->first();
                    if (! $row) {
                        return null;
                    }
                    $row->forceFill(['status' => 'processing', 'attempts' => $row->attempts + 1, 'available_at' => now()->addSeconds($this->timeout + 30)])->save();

                    return $row;
                });
                if (! $event) {
                    continue;
                }
                // Delivery is at-least-once; consumers must deduplicate by event_uuid.
                event($event->event_type, [$event->payload + ['event_uuid' => $event->event_uuid]]);
                $event->forceFill(['status' => 'published', 'published_at' => now(), 'available_at' => null])->save();
            } catch (Throwable $exception) {
                OutboxEvent::query()->whereKey($id)->where('status', 'processing')->update(['status' => 'pending', 'available_at' => now()->addSeconds(30), 'last_error' => mb_substr($exception->getMessage(), 0, 1000)]);
                throw $exception;
            }
        }
    }
}
