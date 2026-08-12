<?php

namespace App\Services\Billing;

use App\Models\Client;
use Illuminate\Support\Facades\Log;

/**
 * Saved cards, via Stripe.
 *
 * WHAT NEVER TOUCHES OUR SERVER: the card number, CVC or expiry. The browser
 * sends those straight to Stripe with Stripe.js/Elements and gets back a
 * PaymentMethod id (`pm_…`). Only that id reaches us. That is what keeps this
 * application in PCI SAQ-A territory despite the payment form being rendered
 * in our own design — the inputs are Stripe-hosted iframes, not our fields.
 *
 * We persist nothing about the card ourselves beyond a brand and last-4 label
 * on `clients` for display. Stripe remains the record of the instrument.
 */
class PaymentMethodService
{
    public function __construct(
        private readonly StripeClientFactory $factory,
        private readonly BillingService $billing,
    ) {
    }

    /**
     * A SetupIntent client secret, for saving a card WITHOUT charging it.
     *
     * Used by "add a card" and "change card": a bare PaymentMethod created in
     * the browser is not authenticated, so a card needing 3-D Secure would
     * only fail later at the first invoice. Confirming a SetupIntent runs that
     * challenge now, while the customer is present.
     */
    public function createSetupIntent(Client $client): string
    {
        $customerRef = $this->billing->ensureCustomer($client);

        $intent = $this->factory->make()->setupIntents->create([
            'customer'             => $customerRef,
            'usage'                => 'off_session',   // future invoices, customer absent
            'payment_method_types' => ['card'],
            'metadata'             => ['client_ref' => (string) $client->getKey()],
        ]);

        return (string) $intent->client_secret;
    }

    /**
     * Every saved card, newest first, with the default flagged.
     *
     * Returns [] rather than throwing — this feeds a page, and a Stripe blip
     * should degrade to "no saved cards" (which shows the new-card form), not
     * a 500 on the billing screen.
     *
     * @return array<int, array{id:string,brand:string,last4:string,exp_month:int,exp_year:int,is_default:bool,expired:bool}>
     */
    public function all(Client $client): array
    {
        if (! $client->stripe_customer_ref || ! $this->factory->isConfigured()) {
            return [];
        }

        try {
            $stripe = $this->factory->make();

            $customer = $stripe->customers->retrieve($client->stripe_customer_ref, []);
            $defaultRef = $customer->invoice_settings->default_payment_method ?? null;

            $methods = $stripe->paymentMethods->all([
                'customer' => $client->stripe_customer_ref,
                'type'     => 'card',
                'limit'    => 20,
            ]);

            $cards = [];

            foreach ($methods->data as $pm) {
                $expMonth = (int) ($pm->card->exp_month ?? 0);
                $expYear  = (int) ($pm->card->exp_year ?? 0);

                $cards[] = [
                    'id'         => $pm->id,
                    'brand'      => (string) ($pm->card->brand ?? 'card'),
                    'last4'      => (string) ($pm->card->last4 ?? '••••'),
                    'exp_month'  => $expMonth,
                    'exp_year'   => $expYear,
                    'is_default' => $pm->id === $defaultRef,
                    'expired'    => $this->isExpired($expMonth, $expYear),
                ];
            }

            // Default first, then the rest — the checkout page pre-selects [0].
            usort($cards, fn ($a, $b) => ($b['is_default'] <=> $a['is_default']));

            return $cards;
        } catch (\Throwable $e) {
            Log::warning('billing.payment_methods.list_failed', [
                'client_id' => $client->getKey(),
                'error'     => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function defaultFor(Client $client): ?array
    {
        foreach ($this->all($client) as $card) {
            if ($card['is_default']) {
                return $card;
            }
        }

        return null;
    }

    /**
     * Attach a PaymentMethod to the workspace and make it the default for
     * future invoices.
     *
     * Idempotent on re-attach: Stripe errors if a PM is already attached to
     * this same customer, which is not a failure worth surfacing.
     */
    public function attach(Client $client, string $paymentMethodRef, bool $makeDefault = true): void
    {
        $customerRef = $this->billing->ensureCustomer($client);
        $stripe      = $this->factory->make();

        try {
            $stripe->paymentMethods->attach($paymentMethodRef, ['customer' => $customerRef]);
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            // Already attached to THIS customer → fine. Attached to another
            // customer → a real problem worth surfacing.
            if (! str_contains($e->getMessage(), 'already been attached')) {
                throw $e;
            }
        }

        if ($makeDefault) {
            $stripe->customers->update($customerRef, [
                'invoice_settings' => ['default_payment_method' => $paymentMethodRef],
            ]);
        }

        $this->cacheLabel($client, $paymentMethodRef);
    }

    public function setDefault(Client $client, string $paymentMethodRef): void
    {
        $this->assertOwnedBy($client, $paymentMethodRef);

        $this->factory->make()->customers->update($client->stripe_customer_ref, [
            'invoice_settings' => ['default_payment_method' => $paymentMethodRef],
        ]);

        $this->cacheLabel($client, $paymentMethodRef);
    }

    /**
     * Remove a saved card.
     *
     * Refuses to detach the last remaining card while a live subscription
     * would still be billed against it — silently leaving a paying customer
     * with no way to pay is worse than refusing the click.
     */
    public function detach(Client $client, string $paymentMethodRef): void
    {
        $this->assertOwnedBy($client, $paymentMethodRef);

        $subscription = $client->currentSubscription();
        $stillBilling = $subscription
            && $subscription->stripe_subscription_ref
            && ! $subscription->cancel_at_period_end
            && $subscription->grantsAccess();

        if ($stillBilling && count($this->all($client)) <= 1) {
            throw new \RuntimeException(
                'This is the only card on your subscription. Add a replacement first, then remove this one.'
            );
        }

        $this->factory->make()->paymentMethods->detach($paymentMethodRef, []);

        // Refresh the cached label from whatever remains.
        $remaining = $this->defaultFor($client->fresh());

        $client->forceFill([
            'pm_type'      => $remaining['brand'] ?? null,
            'pm_last_four' => $remaining['last4'] ?? null,
        ])->save();
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * A payment method id arrives from the browser, so it must be proved to
     * belong to THIS workspace before it is acted on. Without this, a
     * `pm_…` id belonging to another customer could be set as default or
     * detached by anyone who could guess it.
     */
    private function assertOwnedBy(Client $client, string $paymentMethodRef): void
    {
        if (! $client->stripe_customer_ref) {
            throw new \RuntimeException('This workspace has no billing profile yet.');
        }

        $pm = $this->factory->make()->paymentMethods->retrieve($paymentMethodRef, []);

        if (($pm->customer ?? null) !== $client->stripe_customer_ref) {
            throw new \RuntimeException('That payment method does not belong to this workspace.');
        }
    }

    /** Store the brand/last-4 label so the UI can render without a Stripe call. */
    private function cacheLabel(Client $client, string $paymentMethodRef): void
    {
        try {
            $pm = $this->factory->make()->paymentMethods->retrieve($paymentMethodRef, []);

            $client->forceFill([
                'pm_type'      => $pm->card->brand ?? $pm->type ?? null,
                'pm_last_four' => $pm->card->last4 ?? null,
            ])->save();
        } catch (\Throwable $e) {
            Log::info('billing.payment_methods.label_cache_failed', ['error' => $e->getMessage()]);
        }
    }

    private function isExpired(int $month, int $year): bool
    {
        if ($month < 1 || $year < 1) {
            return false;
        }

        // A card is valid through the END of its expiry month.
        return now()->startOfMonth()->gt(
            now()->setDate($year, $month, 1)->startOfMonth()
        );
    }

    /** Human label for a brand: "visa" → "Visa", "amex" → "Amex". */
    public static function brandLabel(?string $brand): string
    {
        return match (strtolower((string) $brand)) {
            'visa'       => 'Visa',
            'mastercard' => 'Mastercard',
            'amex'       => 'Amex',
            'discover'   => 'Discover',
            'jcb'        => 'JCB',
            'diners'     => 'Diners Club',
            'unionpay'   => 'UnionPay',
            ''           => 'Card',
            default      => ucfirst((string) $brand),
        };
    }
}
