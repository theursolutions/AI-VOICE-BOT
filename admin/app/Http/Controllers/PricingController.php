<?php

namespace App\Http\Controllers;

use App\Services\Billing\PricingPresenter;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The public pricing page.
 *
 * Deliberately thin: it validates the interval tab, asks PricingPresenter for
 * a finished view model, and renders. No exchange-rate call, no geolocation
 * call, no price arithmetic happens here — the brief requires that logic stay
 * out of controllers and templates, and keeping it in one presenter is also
 * what lets the billing page reuse the identical "$59 ≈ Rs 16,600" rendering.
 */
class PricingController extends Controller
{
    public function __construct(private readonly PricingPresenter $presenter)
    {
    }

    public function index(Request $request): View
    {
        abort_unless(
            tva_setting('billing.pricing_page_enabled', config('billing.settings.pricing_page_enabled', true)),
            404
        );

        $interval = $request->query('billing');
        $pricing  = $this->presenter->build($request, is_string($interval) ? $interval : null);

        $response = view('pages.pricing', [
            'title'   => 'Pricing',
            'pricing' => $pricing,
        ]);

        // Remember an explicit country choice so the visitor doesn't have to
        // re-pick on every page. Cookie, not session: the pricing page is
        // reachable without one and we don't want to mint sessions for bots.
        if ($request->query(config('billing.geo.query_parameter', 'country'))) {
            $country = $pricing['geo']['country_code'] ?? null;

            if ($country) {
                cookie()->queue(cookie(
                    $this->presenter instanceof PricingPresenter
                        ? (string) config('billing.geo.cookie', 'serveai_country')
                        : 'serveai_country',
                    $country,
                    60 * 24 * (int) config('billing.geo.cookie_days', 90)
                ));
            }
        }

        return $response;
    }
}
