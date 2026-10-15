<?php

namespace App\Models\LeadGen;

use App\Models\Concerns\IntSoftDeletes;

/** One discovery run against a provider; resumable from `cursor`. */
class Search extends LeadGenModel
{
    use IntSoftDeletes;

    public const QUEUED     = 'queued';
    public const RUNNING    = 'running';
    public const PAUSED     = 'paused';
    public const CANCELLING = 'cancelling';
    public const CANCELLED  = 'cancelled';
    public const COMPLETED  = 'completed';
    public const FAILED     = 'failed';

    protected $table = 'lg_searches';

    protected $casts = [
        'criteria'    => 'array',
        'cursor'      => 'array',
        'progress'    => 'array',
        'saved'       => 'boolean',
        'estimate'    => 'integer',
        'started_at'  => 'integer',
        'finished_at' => 'integer',
    ];

    public function results()
    {
        return $this->hasMany(SearchResult::class, 'search_id');
    }

    public function profile()
    {
        return $this->belongsTo(OfferProfile::class, 'profile_id');
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::CANCELLED, self::COMPLETED, self::FAILED], true);
    }
}
