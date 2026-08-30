<?php

namespace App\Http\Controllers\SuperAdmin\Billing;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Every payment taken through a gateway, and every one that was not.
 *
 * THE MISSING HALF OF THE OPS CONSOLE. Subscriptions show what a workspace is
 * entitled to; this shows the money. Until now a Safepay or Paddle payment
 * existed only as a row nobody could see — so "did this customer actually pay?"
 * had no answer short of a database client, and a payment that started and
 * never finished was invisible by construction.
 *
 * PENDING IS THE INTERESTING STATE, not paid. A pending row is somebody who
 * reached a checkout and did not come back: the payment may have succeeded with
 * a webhook that never arrived, or they may simply have changed their mind, and
 * those two look identical from here. That is precisely why they are worth
 * looking at, and why the default view sorts them to the top rather than hiding
 * them behind a filter.
 *
 * STRIPE PAYMENTS ARE NOT HERE and cannot be — they live in Stripe, which bills
 * its own subscriptions and never writes a `gateway_charges` row. The page says
 * so rather than implying this is the whole picture.
 */
class ChargesController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): View
    {
        $filters = [
            'status'  => (string) $request->query('status', ''),
            'gateway' => (string) $request->query('gateway', ''),
            'purpose' => (string) $request->query('purpose', ''),
            'q'       => trim((string) $request->query('q', '')),
        ];

        $query = DB::table('gateway_charges as gc')
            ->leftJoin('clients as c', 'c.id', '=', 'gc.client_id')
            ->leftJoin('plan_prices as pp', 'pp.id', '=', 'gc.plan_price_id')
            ->leftJoin('plans as p', 'p.id', '=', 'pp.plan_id')
            ->select([
                'gc.*',
                'c.name as client_name',
                'c.slug as client_slug',
                'c.billing_country',
                'p.name as plan_name',
                'p.slug as plan_slug',
            ]);

        if ($filters['status'] !== '') {
            $query->where('gc.status', $filters['status']);
        }

        if ($filters['gateway'] !== '') {
            $query->where('gc.gateway', $filters['gateway']);
        }

        if ($filters['purpose'] !== '') {
            $query->where('gc.purpose', $filters['purpose']);
        }

        if ($filters['q'] !== '') {
            $term = '%' . $filters['q'] . '%';

            // Reference, workspace and the gateway's own id — an operator
            // chasing a payment has whichever of the three the customer or the
            // provider gave them, and should not have to know which is which.
            $query->where(function ($q) use ($term) {
                $q->where('gc.reference', 'like', $term)
                  ->orWhere('gc.gateway_ref', 'like', $term)
                  ->orWhere('c.name', 'like', $term)
                  ->orWhere('c.slug', 'like', $term);
            });
        }

        $charges = $query
            ->orderByDesc('gc.created_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('ops.billing.charges.index', [
            'title'    => 'Payments',
            'charges'  => $charges,
            'filters'  => $filters,
            'totals'   => $this->totals(),
            'stale'    => $this->stalePending(),
            'gateways' => DB::table('gateway_charges')->distinct()->pluck('gateway')->filter()->values()->all(),
        ]);
    }

    /**
     * Money in, money waiting, money lost — per currency.
     *
     * PER CURRENCY, never summed across them. Adding rupees to dollars produces
     * a number that is wrong in a way nobody notices, and this page exists to be
     * trusted.
     *
     * @return array<string, array<string, array{count: int, amount: int}>>
     */
    private function totals(): array
    {
        $rows = DB::table('gateway_charges')
            ->select('currency', 'status', DB::raw('COUNT(*) as n'), DB::raw('SUM(amount_cents) as total'))
            ->groupBy('currency', 'status')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[strtoupper($row->currency)][$row->status] = [
                'count'  => (int) $row->n,
                'amount' => (int) $row->total,
            ];
        }

        ksort($out);

        return $out;
    }

    /**
     * Payments that started a while ago and never resolved.
     *
     * The ones worth acting on. A charge pending for minutes is a customer
     * still typing their card number; one pending since last Tuesday is either
     * an abandoned checkout or — the case that matters — a payment the provider
     * took and whose webhook never reached us. Surfaced as a count with a link
     * rather than buried, because nobody goes looking for a problem they have
     * not been told about.
     */
    private function stalePending(): int
    {
        return DB::table('gateway_charges')
            ->where('status', 'pending')
            ->where('created_at', '<', now()->subHours(2))
            ->count();
    }

    /**
     * One payment in full, including the raw provider payload.
     *
     * The payload is what settles an argument. When a customer says they paid
     * and the row says pending, the provider's own words are the only thing
     * that decides it.
     */
    public function show(Request $request, string $reference): View
    {
        $charge = DB::table('gateway_charges')->where('reference', $reference)->first();

        abort_if(! $charge, 404);

        return view('ops.billing.charges.show', [
            'title'  => 'Payment ' . $charge->reference,
            'charge' => $charge,
            'client' => Client::find($charge->client_id),
            'raw'    => $this->prettyPayload($charge->raw),
            'items'  => $this->prettyPayload($charge->line_items),
        ]);
    }

    /** Re-encoded for reading. Left as-is when it is not JSON at all. */
    private function prettyPayload(?string $json): ?string
    {
        if (! $json) {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded)
            ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : $json;
    }
}
