<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Client;
use App\Services\Geo\GeoLocationService;
use App\Support\Payments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * The customer's own answer to "where are you buying from".
 *
 * WHY THIS IS THEIRS TO SET. The country decides the gateway, the currency and
 * the price, and it is seeded from an IP address — which is a guess. A VPN, a
 * founder travelling, a company registered somewhere other than where its
 * people sit, a mobile network routing through another country: all ordinary,
 * and each one otherwise routes somebody to a checkout that cannot take their
 * money, with no way to correct it. So the guess is a default, never a verdict.
 *
 * TWO PLACES IT IS REMEMBERED, deliberately:
 *
 *   a cookie   for anyone — a visitor on the public pricing page has no
 *              workspace to store anything on, and still deserves to see
 *              their own currency on the next page.
 *   the client for a workspace owner — because this is what the gateway
 *              actually routes on at checkout, and a cookie is not something
 *              a payment decision should hang from.
 *
 * NOT A SECURITY BOUNDARY. Choosing a country changes which provider takes the
 * payment and in which currency; it grants nothing, discounts nothing, and
 * cannot reach a plan the workspace is not entitled to. The amount is still
 * resolved server-side from a plan slug. The one thing it must not do is let
 * somebody select a country we have deliberately switched off, which is why the
 * value is checked against the sellable list rather than merely being a
 * two-letter string.
 */
class CountryController extends Controller
{
    public function __construct(private readonly GeoLocationService $geo)
    {
    }

    /**
     * Store the visitor's country and send them back where they came from.
     *
     * A redirect rather than JSON: every price, every currency symbol and the
     * gateway's own name are rendered server-side, so a re-render is both the
     * simplest and the only way to be sure nothing on the page still shows the
     * old currency.
     */
    public function update(Request $request, ?Client $client = null)
    {
        $validated = $request->validate([
            'country' => ['required', 'string', 'size:2', 'alpha'],
        ]);

        $code = strtoupper($validated['country']);

        // Checked against what we will actually sell to, not just against
        // ISO-3166 — an operator who has switched a country off has said we do
        // not take money from there, and a hand-crafted POST must not override
        // that.
        if (! isset(Payments::sellableCountries()[$code])) {
            return back()->with('error', 'We can’t take payments from that country yet. Please contact us and we’ll help.');
        }

        // A year, because it is a preference rather than a session: someone who
        // set it in January should not be re-guessed in March. Not httpOnly —
        // nothing secret is in it, and the front end reads it to show the
        // current selection before a round trip.
        Cookie::queue(Cookie::make($this->geo->cookieName(), $code, 60 * 24 * 365));

        // A workspace owner's choice becomes the workspace's, because that is
        // what checkout routes on. A member's does not: the country on the
        // invoice is not theirs to change.
        $client = $this->workspaceFor($request, $client);

        if ($client) {
            $previous = $client->billing_country;

            // MARKED AS THEIRS, and that mark is the whole point. Until someone
            // chooses, the country keeps following their IP so the prices are
            // right without anyone doing anything. The moment they choose, the
            // guessing stops for good — otherwise a customer who corrected us
            // would be corrected back on their next page load, which is worse
            // than never having asked.
            //
            // Written even when the code is unchanged: picking your own country
            // out of the list is still a decision, and it has to stick.
            $client->forceFill([
                'billing_country' => $code,
                'json_data'       => array_replace_recursive(
                    (array) $client->json_data,
                    ['billing' => ['country_source' => 'manual']],
                ),
                'updated_at'      => time(),
            ])->save();

            if ($previous !== $code) {
                // Recorded because it changes who processes the money and in
                // what currency — the kind of change that has to be explainable
                // months later when an invoice looks wrong.
                AuditLog::record('billing.country.changed', [
                    'payload' => [
                        'client_id' => $client->id,
                        'from'      => $previous,
                        'to'        => $code,
                        'source'    => 'manual',
                    ],
                ]);
            }
        }

        return back();
    }

    /**
     * The workspace this person owns, if the request is for one.
     *
     * TYPE-HINTED ON THE ACTION, not read back out of the route. Implicit
     * route-model binding resolves whatever the CONTROLLER SIGNATURE asks for —
     * SubstituteBindings reads the action's parameters to decide what to
     * replace. With no `Client $client` in the signature there is nothing to
     * substitute, and `$request->route('client')` hands back the raw slug
     * string.
     *
     * Testing that string against `instanceof` looked careful and silently did
     * nothing: an owner changed their country, the page reported no problem,
     * and the workspace was never updated.
     *
     * Optional because the same action serves the public pricing page, where
     * there is no workspace in the URL at all and the choice lives only in a
     * cookie.
     *
     * From the ROUTE either way — never a request field, which would let anyone
     * point the change at somebody else's workspace.
     */
    private function workspaceFor(Request $request, ?Client $client): ?Client
    {
        if (! $client) {
            return null;
        }

        return $request->user()?->isOwnerOf($client->id) ? $client : null;
    }
}
