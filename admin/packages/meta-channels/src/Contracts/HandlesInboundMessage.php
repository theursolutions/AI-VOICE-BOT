<?php

namespace Msd\MetaChannels\Contracts;

use Msd\MetaChannels\Support\InboundMessage;

/**
 * The host app implements this to give the channels engine its "brain".
 * Return the assistant reply text (the package sends it back), or null to
 * stay silent. This is the seam that keeps the package independent of the
 * CRM/LLM pipeline.
 */
interface HandlesInboundMessage
{
    public function handle(InboundMessage $message): ?string;
}
