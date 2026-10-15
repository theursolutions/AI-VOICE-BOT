<?php

namespace App\Models\LeadGen;

/** Append-only execution log line for a search, import, campaign or message. */
class Event extends LeadGenModel
{
    public const UPDATED_AT = null;

    protected $table = 'lg_events';

    protected $casts = ['payload' => 'array'];

    public static function log(string $subjectType, int $subjectId, string $event, array $payload = [], string $level = 'info', ?int $actorId = null): self
    {
        return static::create([
            'subject_type' => $subjectType,
            'subject_id'   => $subjectId,
            'event'        => $event,
            'level'        => $level,
            'payload'      => $payload ?: null,
            'actor_id'     => $actorId ?? auth()->id(),
        ]);
    }
}
