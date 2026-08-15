<?php

namespace Msd\MetaChannels\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Msd\MetaChannels\Contracts\HandlesMessageStatus;

/**
 * One delivery receipt for a message we sent.
 *
 * Queued for the same reason inbound messages are: the webhook has to answer
 * Meta quickly, and a busy number produces three of these per message.
 */
class ProcessMessageStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $backoff = 5;

    public function __construct(
        public int $projectId,
        public string $wamid,
        public string $status,      // sent | delivered | read | failed
        public int $timestamp,
        public string $error = '',
    ) {}

    public function handle(HandlesMessageStatus $handler): void
    {
        $handler->handle($this->projectId, $this->wamid, $this->status, $this->timestamp, $this->error);
    }
}
