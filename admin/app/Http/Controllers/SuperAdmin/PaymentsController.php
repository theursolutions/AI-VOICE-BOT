<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Billing\Gateways\GatewayRegistry;
use App\Services\Billing\Gateways\PaymentGateway;
use App\Support\Payments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Super-admin payment switchboard: who may take money, and from where.
 *
 * The migration plan lives here rather than in a deploy. Today Safepay serves
 * Pakistan and Paddle serves everywhere else; the day a foreign entity makes
 * Stripe available, switching those two off and Stripe on is the entire change
 * — no code, no release, and nothing deleted to get there.
 *
 * TWO FACTS ARE SHOWN SEPARATELY AND MUST NOT BE CONFLATED. "Enabled" is
 * permission, granted here. "Configured" is capability, and comes from whether
 * the credentials exist in the environment. A gateway needs both to take a
 * payment, and an operator who switches one on without credentials should be
 * told exactly that rather than discovering it through a customer.
 *
 * SWITCHING EVERYTHING OFF IS REFUSED. It is never a deliberate act — nobody
 * means "nobody may pay us" — and the state is invisible from the customer's
 * side, where it looks like the checkout is broken. The audit trail would show
 * who did it, days later, which is not the same as preventing it.
 */
class PaymentsController extends Controller
{
    public function __construct(private readonly GatewayRegistry $registry)
    {
    }

    public function index(Request $request): View
    {
        $gateways = [];

        foreach ($this->registry->all() as $gateway) {
            $gateways[] = [
                'key'          => $gateway->key(),
                'name'         => $this->nameFor($gateway->key()),
                'label'        => $gateway->label(),
                'blurb'        => $this->blurbFor($gateway->key()),
                'enabled'      => Payments::gatewayEnabled($gateway->key()),
                'configured'   => $gateway->isConfigured(),
                'usable'       => $this->registry->isUsable($gateway),
                'currencies'   => $gateway->currencies(),
                'recurring'    => $gateway->supports(PaymentGateway::CAP_RECURRING),
                'countries'    => $this->countriesServedBy($gateway->key()),
                'env_keys'     => $this->envKeysFor($gateway->key()),
            ];
        }

        $allowed = Payments::allowedCountries();

        return view('ops.payments.index', [
            'title'      => 'Payments',
            'gateways'   => $gateways,
            // Every country we have a currency for, not merely the sellable
            // ones — this page is where the restriction is edited, so it has to
            // show what is currently excluded as well as what is not.
            'countries'  => $this->allCountries(),
            'allowed'    => $allowed,
            'unrestricted' => $allowed === null,

            'taxMode'    => Payments::taxMode(),
            'taxRates'   => Payments::taxRates(),
            // Only the countries where WE are the seller can carry a rate we
            // set. A Merchant of Record computes its own, and a rate entered
            // against one of its countries would never be used — showing the
            // field would be an invitation to a mistake.
            'taxSelfServed' => $this->selfServedCountries(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'gateways'     => ['array'],
            'gateways.*'   => ['string'],
            'restrict'     => ['nullable', 'in:0,1'],
            'countries'    => ['array'],
            'countries.*'  => ['string', 'size:2'],
            'tax_mode'     => ['nullable', 'in:' . implode(',', Payments::TAX_MODES)],
            'tax_rates'    => ['array'],
            'tax_rates.*'  => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $enabled = array_values(array_intersect(
            (array) $request->input('gateways', []),
            Payments::KNOWN,
        ));

        // Refused rather than saved. See the class note: an empty set is never
        // intended, and its only symptom is a checkout that appears broken.
        if ($enabled === []) {
            return back()->with('error',
                'At least one payment provider has to stay switched on — with none, nobody can buy a plan.');
        }

        $before = [
            'gateways'  => Payments::enabledGateways(),
            'countries' => Payments::allowedCountries(),
            'tax_mode'  => Payments::taxMode(),
            'tax_rates' => Payments::taxRates(),
        ];

        Payments::setEnabledGateways($enabled);

        if ((string) $request->input('restrict') === '1') {
            $countries = (array) $request->input('countries', []);

            if ($countries === []) {
                return back()->with('error',
                    'Restricting by country needs at least one country selected. '
                    . 'To sell everywhere, turn the restriction off instead.');
            }

            Payments::setAllowedCountries($countries);
        } else {
            // Not the same as saving an empty list, which would mean "nobody".
            Payments::allowAllCountries();
        }

        if ($request->filled('tax_mode')) {
            Payments::setTaxMode((string) $request->input('tax_mode'));
        }

        Payments::setTaxRates((array) $request->input('tax_rates', []));

        AuditLog::record('payments.update', [
            'payload' => [
                'before' => $before,
                'after'  => [
                    'gateways'  => Payments::enabledGateways(),
                    'countries' => Payments::allowedCountries(),
                    'tax_mode'  => Payments::taxMode(),
                    'tax_rates' => Payments::taxRates(),
                ],
            ],
        ]);

        // Named rather than counted, because "2 providers enabled" does not
        // tell an operator whether they just switched off the one taking all
        // their money.
        $names = implode(', ', array_map(fn ($k) => $this->nameFor($k), Payments::enabledGateways()));

        $warning = $this->unusable();

        return back()->with(
            $warning ? 'warning' : 'success',
            'Taking payments through ' . $names . '.'
            . ($warning ? ' ' . $warning : '')
        );
    }

    /**
     * A gateway switched on that cannot actually run, said plainly.
     *
     * The failure it prevents is specific: an operator enables Paddle, the page
     * says saved, and every international customer meets a checkout that cannot
     * start — because the credentials were never added to the environment.
     */
    private function unusable(): ?string
    {
        $broken = [];

        foreach ($this->registry->all() as $gateway) {
            if (Payments::gatewayEnabled($gateway->key()) && ! $gateway->isConfigured()) {
                $broken[] = $this->nameFor($gateway->key());
            }
        }

        if ($broken === []) {
            return null;
        }

        return count($broken) === 1
            ? $broken[0] . ' is switched on but has no credentials, so it will be skipped.'
            : implode(' and ', $broken) . ' are switched on but have no credentials, so they will be skipped.';
    }

    /** Which countries route to this gateway, for the card's summary line. */
    private function countriesServedBy(string $key): string
    {
        foreach (Payments::LOCAL_COUNTRIES as $country => $gateways) {
            if (in_array($key, $gateways, true)) {
                return app(\App\Services\Geo\GeoLocationService::class)->countryName($country) ?: $country;
            }
        }

        return in_array($key, Payments::INTERNATIONAL, true) ? 'Everywhere else' : '—';
    }

    private function allCountries(): array
    {
        $geo = app(\App\Services\Geo\GeoLocationService::class);
        $out = [];

        foreach ((array) config('billing.country_currency', []) as $code => $currency) {
            $code = strtoupper($code);

            $out[$code] = [
                'code'     => $code,
                'name'     => $geo->countryName($code) ?: $code,
                'currency' => strtoupper((string) $currency),
                'flag'     => Payments::flag($code),
            ];
        }

        uasort($out, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $out;
    }

    /**
     * Countries where WE are the seller, so the tax rate is ours to set.
     *
     * Everywhere served only by a Merchant of Record is excluded: it computes
     * and remits its own rates, and a figure entered here would sit in the
     * settings looking authoritative while never being used once.
     *
     * @return array<string, array{code: string, name: string, gateway: string}>
     */
    private function selfServedCountries(): array
    {
        $geo = app(\App\Services\Geo\GeoLocationService::class);
        $out = [];

        foreach (Payments::LOCAL_COUNTRIES as $code => $gateways) {
            foreach ($gateways as $key) {
                $gateway = $this->registry->get($key);

                if (! $gateway || ! $this->registry->isUsable($gateway)) {
                    continue;
                }

                $out[$code] = [
                    'code'    => $code,
                    'name'    => $geo->countryName($code) ?: $code,
                    'gateway' => $this->nameFor($key),
                ];

                break;
            }
        }

        return $out;
    }

    private function nameFor(string $key): string
    {
        return match ($key) {
            'safepay' => 'Safepay',
            'payfast' => 'PayFast',
            'paddle'  => 'Paddle',
            'stripe'  => 'Stripe',
            default   => ucfirst($key),
        };
    }

    private function blurbFor(string $key): string
    {
        return match ($key) {
            'safepay' => 'State Bank licensed. Settles rupees and takes JazzCash, Easypaisa and local cards — none of which an international processor will do.',
            'payfast' => 'A second Pakistani option, kept registered so swapping local providers is a setting rather than a release.',
            'paddle'  => 'Merchant of Record: Paddle sells in its own name and carries the VAT, GST and sales tax, so selling abroad needs no foreign entity.',
            'stripe'  => 'Needs a company in a country Stripe supports. Once that exists it can serve everywhere, and the others can be switched off.',
            default   => '',
        };
    }

    /** The env vars this gateway reads, so a missing one can be found. */
    private function envKeysFor(string $key): array
    {
        return match ($key) {
            'safepay' => ['SAFEPAY_API_KEY', 'SAFEPAY_V1_SECRET', 'SAFEPAY_WEBHOOK_SECRET'],
            'payfast' => ['PAYFAST_MERCHANT_ID', 'PAYFAST_SECURED_KEY'],
            'paddle'  => ['PADDLE_API_KEY', 'PADDLE_CLIENT_TOKEN', 'PADDLE_WEBHOOK_SECRET'],
            'stripe'  => ['STRIPE_KEY', 'STRIPE_SECRET', 'STRIPE_WEBHOOK_SECRET'],
            default   => [],
        };
    }
}
