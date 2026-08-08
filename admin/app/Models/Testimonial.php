<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A customer testimonial rendered in the homepage carousel.
 *
 * Lives in the central DB next to the other marketing-site content; managed
 * by super-admins at /admin/testimonials.
 */
class Testimonial extends Model
{
    protected $connection = 'mysql';
    protected $table = 'testimonials';

    protected $fillable = [
        'name', 'role', 'company', 'quote', 'avatar_url',
        'rating', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'rating'     => 'integer',
        'sort_order' => 'integer',
        'is_active'  => 'boolean',
    ];

    /** Active rows only. */
    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /** Display order: explicit sort_order first, then insertion order. */
    public function scopeOrdered(Builder $q): Builder
    {
        return $q->orderBy('sort_order')->orderBy('id');
    }

    /**
     * What the public homepage renders. Degrades to an empty collection if
     * the table isn't there yet (migration not run) rather than 500-ing the
     * marketing site — the same posture SiteSetting takes.
     */
    public static function forSite(): Collection
    {
        try {
            return self::query()->active()->ordered()->get();
        } catch (\Throwable $e) {
            return collect();
        }
    }

    /** Up to two initials, used for the avatar when no photo is set. */
    public function getInitialsAttribute(): string
    {
        $parts = preg_split('/\s+/', trim((string) $this->name)) ?: [];

        return collect($parts)
            ->filter()
            ->take(2)
            ->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))
            ->implode('') ?: '★';
    }

    /** "Head of Sales · Meridian Properties" — either half may be missing. */
    public function getAttributionAttribute(): string
    {
        return collect([$this->role, $this->company])
            ->filter(fn ($v) => trim((string) $v) !== '')
            ->implode(' · ');
    }
}
