<?php

namespace App\Jobs;

use App\Services\Outbox\OutboxProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessOutboxMessage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly int $messageId) {}

    public function handle(OutboxProcessor $processor): void
    {
        $processor->process($this->messageId);
    }
}
