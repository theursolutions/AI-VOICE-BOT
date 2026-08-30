<?php

namespace App\Services\Billing;

use App\Models\Client;
use App\Services\Billing\Gateways\PaymentGateway;
use App\Support\Payments;

/**
 * Whether the sticker price is the total, and who works the tax out.
 *
 * TWO QUESTIONS THAT LOOK LIKE ONE, and conflating them is how a customer ends
 * up charged twice.
 *
 *   1. Is tax INSIDE the price or added to it?  — an operator's policy.
 *   2. Who CALCULATES and REMITS it?            — decided by the provider.
 *
 * A Merchant of Record answers (2) itself: it knows the rate in every
 * jurisdiction it sells into, applies it, and files the return. All we do is
 * tell it which way round our prices are quoted. Adding our own tax on top of
 * that would charge the customer twice and leave us holding money we have no
 * way to remit.
 *
 * A plain processor answers nothing. Safepay moves the money and we are the
 * seller, so the tax is ours to compute, show and account for — which is why a
 * rate lives in settings at all.
 *
 * MODES, in the operator's words rather than the provider's:
 *
 *   inclusive  the price IS the total. Tax is carved out for reporting.
 *   exclusive  tax is added at checkout, so the customer pays more.
 *   auto       whichever the customer's country expects. Inclusive in the UK
 *              and EU, where quoting a consumer price ex-VAT is unlawful;
 *              exclusive in the US, where sales tax is added at the till.
 *
 * `auto` is the honest default for selling internationally, because any single
 * choice is wrong somewhere: an American shown a tax-inclusive price thinks you
 * are expensive, and a German shown an ex-VAT one thinks you are cheap until
 * the invoice lands.
 */
class TaxService
{
    /**
     * Countries that expect a consumer price to INCLUDE tax.
     *
     * The EU, the UK and the other VAT/GST regimes where advertising an
     * ex-tax price to a consumer is either unlawful or simply not done. Used
     * only by `auto`; anywhere not listed is treated as tax-exclusive, which is
     * the US convention.
     */
    private const TAX_INCLUSIVE_COUNTRIES = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR',
        'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK',
        'SI', 'ES', 'SE', 'GB', 'NO', 'CH', 'AU', 'NZ', 'IN', 'PK', 'ZA', 'AE',
        'SA', 'SG', 'MY', 'JP',
    ];

    /** The operator's policy: inclusive | exclusive | auto. */
    public function mode(): string
    {
        return Payments::taxMode();
    }

    /**
     * How the price should be treated for one country: inclusive or exclusive.
     *
     * `auto` resolved to a concrete answer. Everything downstream wants a
     * decision, not a policy.
     */
    public function modeFor(?string $country): string
    {
        $mode = $this->mode();

        if ($mode !== 'auto') {
            return $mode;
        }

        $code = strtoupper(trim((string) $country));

        return in_array($code, self::TAX_INCLUSIVE_COUNTRIES, true) ? 'inclusive' : 'exclusive';
    }

    /**
     * Does the PROVIDER handle tax, leaving us nothing to add?
     *
     * True for a Merchant of Record. The distinction is the capability, not the
     * provider's name, so a gateway added later answers by declaring it.
     */
    public function handledByProvider(?PaymentGateway $gateway): bool
    {
        return (bool) $gateway?->supports(PaymentGateway::CAP_HOSTED_INVOICES)
            && (bool) $gateway?->supports(PaymentGateway::CAP_PROVIDER_SUBSCRIPTIONS);
    }

    /**
     * Break an amount into what the customer pays and what of it is tax.
     *
     * @param  int  $amount  the plan price, in minor units
     *
     * @return array{
     *   mode: string, rate: float, handled_by: string,
     *   subtotal: int, tax: int, total: int, show: bool
     * }
     */
    public function breakdown(Client $client, int $amount, ?PaymentGateway $gateway = null): array
    {
        $country = $client->billing_country;
        $mode    = $this->modeFor($country);

        // The provider computes and remits its own. We must NOT add to the
        // amount — it would be charged on top of the tax they already applied —
        // and we cannot state a figure either, because only they know the rate
        // for a customer whose address we have not seen yet.
        if ($this->handledByProvider($gateway)) {
            return [
                'mode'       => $mode,
                'rate'       => 0.0,
                'handled_by' => 'provider',
                'subtotal'   => $amount,
                'tax'        => 0,
                'total'      => $amount,
                'show'       => false,
            ];
        }

        $rate = Payments::taxRateFor($country);

        if ($rate <= 0) {
            return [
                'mode'       => $mode,
                'rate'       => 0.0,
                'handled_by' => 'none',
                'subtotal'   => $amount,
                'tax'        => 0,
                'total'      => $amount,
                'show'       => false,
            ];
        }

        if ($mode === 'inclusive') {
            // Carved OUT of the price, not added: the customer pays the sticker
            // price and some of it was always tax. tax = total × r / (100 + r).
            $tax = (int) round($amount * $rate / (100 + $rate));

            return [
                'mode'       => 'inclusive',
                'rate'       => $rate,
                'handled_by' => 'us',
                'subtotal'   => $amount - $tax,
                'tax'        => $tax,
                'total'      => $amount,
                'show'       => true,
            ];
        }

        // Added on top. The customer pays MORE than the sticker price, so this
        // is the case that has to be visible before they press the button.
        $tax = (int) round($amount * $rate / 100);

        return [
            'mode'       => 'exclusive',
            'rate'       => $rate,
            'handled_by' => 'us',
            'subtotal'   => $amount,
            'tax'        => $tax,
            'total'      => $amount + $tax,
            'show'       => true,
        ];
    }

    /**
     * What the customer is actually charged, after tax policy is applied.
     *
     * The single number the gateway is told to collect. Everything else in the
     * breakdown is presentation; this one is money.
     */
    public function chargeableTotal(Client $client, int $amount, ?PaymentGateway $gateway = null): int
    {
        return $this->breakdown($client, $amount, $gateway)['total'];
    }

    /**
     * The tax_mode to give Paddle when minting a price.
     *
     *   internal  amounts are inclusive of tax
     *   external  amounts are exclusive of tax
     *   location  Paddle decides per customer, which is what `auto` means
     *
     * Taken from Paddle's own vocabulary rather than ours, and mapped here so
     * that exactly one place has to know both.
     */
    public function paddleTaxMode(): string
    {
        return match ($this->mode()) {
            'exclusive' => 'external',
            'auto'      => 'location',
            default     => 'internal',
        };
    }
}
