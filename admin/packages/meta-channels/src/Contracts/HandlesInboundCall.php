<?php

namespace Msd\MetaChannels\Contracts;

use Msd\MetaChannels\Support\InboundCall;

/**
 * The host app implements this to bridge a WhatsApp call's SDP offer to its
 * media stack (e.g. the Python WebRTC engine) and return an SDP answer. The
 * package then relays the answer to Meta (pre_accept + accept).
 */
interface HandlesInboundCall
{
    /** Return the SDP answer for the offer, or null to reject the call. */
    public function answer(InboundCall $call): ?string;

    /** Called when Meta reports the call ended. */
    public function onTerminate(string $callId): void;
}
