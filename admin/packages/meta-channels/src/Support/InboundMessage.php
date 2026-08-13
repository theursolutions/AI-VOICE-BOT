<?php

namespace Msd\MetaChannels\Support;

/**
 * A normalised inbound text message, channel-agnostic. Passed to the host
 * app's HandlesInboundMessage implementation.
 */
class InboundMessage
{
    /**
     * @param array<int, array{type:string, media_id?:string, url?:string, mime?:string, filename?:string, caption?:string}> $attachments
     */
    public function __construct(
        public int $projectId,
        public string $provider,        // whatsapp | instagram | ...
        public string $channelExternalId, // business phone_number_id / page id
        public string $from,            // sender wa_id / psid
        public ?string $senderName,
        public string $text,
        public ?string $messageId = null,
        public ?string $accessToken = null,
        public ?string $profilePic = null,
        public array $attachments = [],
        // Graph host this channel's token is valid against. Null means the
        // default (graph.facebook.com); Instagram accounts onboarded via
        // Instagram Login carry graph.instagram.com, whose tokens the
        // Facebook host rejects.
        public ?string $graphBase = null,
    ) {}
}
