<?php

namespace App\Models\Billing;

use App\Models\Client;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A workspace's subscription. Covers BOTH the free window (no Stripe object
 * at all) and paid/trialing states (mirrored from Stripe).
 *
 * STRIPE IS AUTHORITATIVE for paid state. `stripe_status` is written only by
 * StripeWebhookController or an explicit reconcile. Never infer paid state
 * from a redirect back from Checkout — the customer can close the tab, and
 * the success URL is a page the user can simply visit.
 */
class Subscription extends Model
{
    protected $connection = 'mysql';
    protected $table = 'subscriptions';

    // ── Our normalised statuses (superset of Stripe's) ───────────────
    public const STATUS_FREE               = 'free';
    public const STATUS_TRIALING           = 'trialing';
    public const STATUS_ACTIVE             = 'active';
    public const STATUS_PAST_DUE           = 'past_due';
    public const STATUS_CANCELED           = 'canceled';
    public const STATUS_UNPAID             = 'unpaid';
    public const STATUS_INCOMPLETE         = 'incomplete';
    public const STATUS_INCOMPLETE_EXPIRED = 'incomplete_expired';
    public const STATUS_PAUSED             = 'paused';
    /** Free window elapsed with no payment. Stripe has no equivalent. */
    public const STATUS_EXPIRED            = 'expired';

    protected $fillable = [
        'client_id', 'plan_id', 'plan_price_id', 'type', 'status',
        'stripe_subscription_ref', 'stripe_customer_ref', 'stripe_price_ref',
        'stripe_status', 'quantity', 'interval', 'unit_amount', 'currency',
        'free_started_at', 'free_ends_at', 'trial_ends_at',
        'current_period_start', 'current_period_end',
        'cancel_at_period_end', 'canceled_at', 'ends_at',
        'past_due_since', 'read_only_since', 'purge_after', 'metadata',
    ];

    protected $casts = [
        'client_id'            => 'integer',
        'plan_id'              => 'integer',
        'plan_price_id'        => 'integer',
        'quantity'             => 'integer',
        'unit_amount'          => 'integer',
        'cancel_at_period_end' => 'boolean',
        'free_started_at'      => 'datetime',
        'free_ends_at'         => 'datetime',
        'trial_ends_at'        => 'datetime',
        'current_period_start' => 'datetime',
        'current_period_end'   => 'datetime',
        'canceled_at'          => 'datetime',
        'ends_at'              => 'datetime',
        'past_due_since'       => 'datetime',
        'read_only_since'      => 'datetime',
        'purge_after'          => 'datetime',
        'metadata'             => 'array',
    ];

    // ── Relations ────────────────────────────────────────────────────

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function planPrice(): BelongsTo
    {
        return $this->belongsTo(PlanPrice::class, 'plan_price_id');
    }

    // ── State ────────────────────────────────────────────────────────

    /**
     * Does this subscription currently grant full application access?
     *
     * Note free windows are checked against the clock, not just the stored
     * status: the daily expiry command may not have run yet, and access must
     * not depend on a cron having fired on time.
     */
    public function grantsAccess(): bool
    {
        if ($this->status === self::STATUS_FREE) {
            return ! $this->freeWindowHasElapsed();
        }

        if (in_array($this->status, (array) config('billing.lifecycle.active_statuses', []), true)) {
            return true;
        }

        if ($this->status === self::STATUS_PAST_DUE) {
            return $this->withinPastDueGrace();
        }

        return false;
    }

    public function isFree(): bool
    {
        return $this->status === self::STATUS_FREE;
    }

    public function onTrial(): bool
    {
        return $this->status === self::STATUS_TRIALING
            && $this->trial_ends_at
            && $this->trial_ends_at->isFuture();
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isPastDue(): bool
    {
        return $this->status === self::STATUS_PAST_DUE;
    }

    public function isCanceled(): bool
    {
        return in_array($this->status, [self::STATUS_CANCELED, self::STATUS_UNPAID], true);
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED;
    }

    /** Cancelled but still inside the period the customer already paid for. */
    public function onGracePeriod(): bool
    {
        return $this->cancel_at_period_end
            && $this->ends_at
            && $this->ends_at->isFuture();
    }

    public function isReadOnly(): bool
    {
        return $this->read_only_since !== null;
    }

    // ── Free window ──────────────────────────────────────────────────

    public function freeWindowHasElapsed(): bool
    {
        // NULL free_ends_at on a free subscription = permanent free tier.
        return $this->free_ends_at !== null && $this->free_ends_at->isPast();
    }

    /** Whole days left, floored at 0. Null when there is no free window. */
    public function freeDaysRemaining(): ?int
    {
        if (! $this->free_ends_at) {
            return null;
        }

        return max(0, now()->diffInDays($this->free_ends_at, false));
    }

    // ── Dunning ──────────────────────────────────────────────────────

    /**
     * A bounced card shouldn't instantly silence a customer's phone line, so
     * past_due keeps access for a configured grace window measured from the
     * FIRST failure.
     */
    public function withinPastDueGrace(): bool
    {
        if (! $this->past_due_since) {
            return true;   // failure not yet stamped; be generous
        }

        $days = (int) config('billing.lifecycle.past_due_grace_days', 7);

        return $this->past_due_since->copy()->addDays($days)->isFuture();
    }

    // ── Renewal ──────────────────────────────────────────────────────

    public function nextBillingDate(): ?CarbonInterface
    {
        if ($this->cancel_at_period_end) {
            return null;   // nothing further will be charged
        }

        return $this->current_period_end;
    }

    /** Human label for the billing page and the ops console. */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_FREE               => $this->freeWindowHasElapsed() ? 'Free window ended' : 'Free',
            self::STATUS_TRIALING           => 'Trial',
            self::STATUS_ACTIVE             => $this->cancel_at_period_end ? 'Active — cancels at period end' : 'Active',
            self::STATUS_PAST_DUE           => 'Payment failed',
            self::STATUS_CANCELED           => 'Canceled',
            self::STATUS_UNPAID             => 'Unpaid',
            self::STATUS_INCOMPLETE         => 'Awaiting payment',
            self::STATUS_INCOMPLETE_EXPIRED => 'Payment not completed',
            self::STATUS_PAUSED             => 'Paused',
            self::STATUS_EXPIRED            => 'Expired',
            default                         => ucfirst((string) $this->status),
        };
    }

    /** Tailwind badge colour used by both the ops list and the billing page. */
    public function statusColor(): string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE                       => 'green',
            self::STATUS_TRIALING, self::STATUS_FREE  => 'blue',
            self::STATUS_PAST_DUE, self::STATUS_UNPAID=> 'amber',
            self::STATUS_EXPIRED, self::STATUS_CANCELED => 'red',
            default                                   => 'slate',
        };
    }

    // ── Scopes ───────────────────────────────────────────────────────

    public function scopeActive($q)
    {
        return $q->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_TRIALING]);
    }

    public function scopeOnFreeWindow($q)
    {
        return $q->where('status', self::STATUS_FREE);
    }

    /** Free windows that have elapsed but not yet been degraded. */
    public function scopeFreeWindowLapsed($q)
    {
        return $q->where('status', self::STATUS_FREE)
                 ->whereNotNull('free_ends_at')
                 ->where('free_ends_at', '<=', now())
                 ->whereNull('read_only_since');
    }

    public function scopeDueForPurge($q)
    {
        return $q->whereNotNull('purge_after')->where('purge_after', '<=', now());
    }
}
