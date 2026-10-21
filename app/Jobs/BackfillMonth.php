<?php

namespace App\Jobs;

use App\Services\AirQuality\BackfillService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class BackfillMonth implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 21600;

    public function __construct(public string $month, public string $from, public string $to) {}

    public function backoff(): array
    {
        return [60, 300, 1800];
    }

    public function handle(BackfillService $service): void
    {
        $service->month($this->month, $this->from, $this->to);
    }
}
