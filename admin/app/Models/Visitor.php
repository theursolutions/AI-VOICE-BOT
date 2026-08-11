<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One distinct visitor to the public marketing site.
 *
 * Built entirely from request headers — nothing is asked of the visitor and
 * no tracking script runs in their browser. See the migration for what each
 * column is derived from.
 */
class Visitor extends Model
{
    protected $connection = 'mysql';
    protected $table = 'visitors';

    protected $guarded = ['id'];

    protected $casts = [
        'is_bot'        => 'boolean',
        'page_views'    => 'integer',
        'latitude'      => 'float',
        'longitude'     => 'float',
        'first_seen_at' => 'datetime',
        'last_seen_at'  => 'datetime',
    ];

    public const GEO_PENDING = 'pending';
    public const GEO_DONE    = 'done';
    public const GEO_PRIVATE = 'private';
    public const GEO_FAILED  = 'failed';

    public function pageViews(): HasMany
    {
        return $this->hasMany(VisitorPageView::class);
    }

    /** Leads captured from this visitor (reach-out bar, contact form). */
    public function leads(): HasMany
    {
        return $this->hasMany(ContactLead::class, 'visitor_key', 'visitor_key');
    }

    public function scopeHumans(Builder $q): Builder
    {
        return $q->where('is_bot', false);
    }

    /** Addresses still worth a geolocation attempt. */
    public function scopeNeedsGeo(Builder $q): Builder
    {
        return $q->where('geo_status', self::GEO_PENDING)->whereNotNull('ip');
    }

    /** "Lahore, Pakistan" — or as much of it as we managed to resolve. */
    public function getLocationAttribute(): string
    {
        $parts = array_filter([$this->city, $this->region !== $this->city ? $this->region : null, $this->country]);

        return $parts ? implode(', ', $parts) : '';
    }

    /**
     * Regional-indicator flag emoji for the ISO country code. Purely a
     * display nicety; returns '' when we have no code.
     */
    public function getFlagAttribute(): string
    {
        $cc = strtoupper((string) $this->country_code);
        if (strlen($cc) !== 2 || ! ctype_alpha($cc)) {
            return '';
        }

        return mb_chr(0x1F1E6 + (ord($cc[0]) - 65), 'UTF-8')
             . mb_chr(0x1F1E6 + (ord($cc[1]) - 65), 'UTF-8');
    }
}
