<?php
namespace App\Jobs;

use App\Services\OrderIntegration;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class TransferOrder implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 30;
    public array $backoff = [10, 30];

    public function __construct(public array $order) {}

    public function handle(OrderIntegration $service): void
    {
        $service->transfer($this->order);
    }
}
