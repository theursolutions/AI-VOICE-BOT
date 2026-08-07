<?php

namespace Msd\MetaChannels\Support;

/**
 * A normalised inbound WhatsApp call 'connect' event. Passed to the host
 * app's HandlesInboundCall implementation, which returns an SDP answer.
 */
class InboundCall
{
    public function __construct(
        public int $projectId,
        public string $callId,
        public string $channelExternalId, // business phone_number_id
        public string $from,
        public string $sdpOffer,
        public ?string $accessToken = null,
    ) {}
}
