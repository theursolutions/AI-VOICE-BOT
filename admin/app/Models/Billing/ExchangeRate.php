<?php

namespace App\Models\Billing;

use Illuminate\Database\Eloquent\Model;

/**
 * A stored USD→X rate. DISPLAY ONLY — never reaches Stripe.
 *
 * This is the durable middle tier of the read path
 * (cache → this table → USD-only), so a cold cache plus a provider outage
 * still renders a local price instead of nothing.
 */
class ExchangeRate extends Model
{
    protected $connection = 'mysql';
    protected $table = 'exchange_rates';

    protected $fillable = ['base', 'currency', 'rate', 'provider', 'fetched_at'];

    protected $casts = [
        'rate'       => 'float',
        'fetched_at' => 'datetime',
    ];

    /**
     * Too old to show? A wrong-looking number is worse than no number, even
     * with an "approximate" label — so past this age we render USD only.
     */
    public function isStale(): bool
    {
        $maxAge = (int) config('billing.fx.max_age_hours', 72);

        return ! $this->fetched_at || $this->fetched_at->diffInHours(now()) > $maxAge;
    }

    public function scopeFresh($q)
    {
        return $q->where('fetched_at', '>=', now()->subHours((int) config('billing.fx.max_age_hours', 72)));
    }
}
