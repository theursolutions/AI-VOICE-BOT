<?php

namespace Msd\MailChannel\Contracts;

use Msd\MailChannel\Support\InboundEmail;

/**
 * The host app implements this to turn an inbound email into a reply.
 *
 * Same shape as Msd\MetaChannels\Contracts\HandlesInboundMessage — the
 * package never imports the CRM brain, it only calls this contract and sends
 * back whatever plain-text reply comes out (or nothing, when null).
 */
interface HandlesInboundEmail
{
    public function handle(InboundEmail $email): ?string;
}
