<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\DeliveryService;
use Illuminate\Console\Command;

class DispatchWhatsAppReminders extends Command
{
    protected $signature = 'whatsapp:dispatch';

    protected $description = 'Antrekan pengingat tagihan WhatsApp yang memenuhi jadwal, izin, persetujuan, dan batas pengulangan.';

    public function handle(DeliveryService $delivery): int
    {
        $count = $delivery->dispatchScheduled();
        $this->info($count.' pengingat memenuhi jadwal dan telah diantrekan.');

        return self::SUCCESS;
    }
}
