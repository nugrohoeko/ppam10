<?php

namespace App\Jobs;

use App\Services\WhatsApp\DeliveryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendWhatsAppReminder implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public int $reminderId) {}

    public function handle(DeliveryService $delivery): void
    {
        if ($delay = $delivery->process($this->reminderId)) {
            // Deferral is a new queue timer, never an HTTP request retry.
            self::dispatch($this->reminderId)->delay(now()->addSeconds($delay))->afterCommit();
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(DeliveryService::class)->markInterrupted($this->reminderId);
    }
}
