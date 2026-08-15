<?php

namespace Msd\MetaChannels\Contracts;

/**
 * Records a delivery receipt for a message the business sent.
 *
 * Implemented by the host app, because resolving a wamid to a stored message
 * means touching the tenant database — which this package deliberately knows
 * nothing about.
 */
interface HandlesMessageStatus
{
    /**
     * @param string $status sent | delivered | read | failed
     * @param string $error  Meta's reason, present only on `failed`
     */
    public function handle(int $projectId, string $wamid, string $status, int $timestamp, string $error = ''): void;
}
