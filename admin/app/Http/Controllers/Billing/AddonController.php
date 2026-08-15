<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Client;
use App\Services\Billing\AddonService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Buying extra seats / AI agents on top of a plan.
 *
 * The form submits an add-on SLUG and a QUANTITY — never a price. Same trust
 * boundary as checkout: the amount is resolved server-side from the add-on's
 * `plan_prices` row for the subscription's own billing interval.
 *
 * Field is `addon`, not `addon_id`: DecodeHashids rewrites request keys
 * matching `*_id`. See SUBSCRIPTION_REQUIREMENTS_ANALYSIS.md §5 C1.
 */
class AddonController extends Controller
{
    public function __construct(private readonly AddonService $addons)
    {
    }

    /**
     * The add-on shop: pick a quantity, see the cost, confirm.
     *
     * A workspace that already has a plan should never be shown the plan
     * ladder again just to buy one more seat — "upgrade a tier" is the wrong
     * answer to "I need one more person". Anyone without a live subscription
     * is sent to choose a plan first, because there is no subscription for a
     * subscription item to attach to.
     */
    public function index(Request $request, Client $client): RedirectResponse|View
    {
        $this->authorizeOwner($request, $client);

        $subscription = $client->currentSubscription();

        if (! $subscription?->stripe_subscription_ref || ! $subscription->grantsAccess()) {
            return redirect()
                ->route('billing.plans', ['client' => $client->slug])
                ->with('info', 'Choose a plan first — add-ons attach to an existing subscription.');
        }

        $cards = app(\App\Services\Billing\PaymentMethodService::class)->all($client);

        return view('billing.addons', [
            'title'        => 'Add extra capacity',
            'client'       => $client,
            'subscription' => $subscription,
            'plan'         => $subscription->plan,
            'addons'       => $this->addons->available($client),
            'addonTotal'   => $this->addons->monthlyTotalCents($client),
            'cards'        => $cards,
            'defaultCard'  => collect($cards)->firstWhere('is_default', true) ?: ($cards[0] ?? null),
            'checkoutOpen' => (bool) config('billing.checkout.enabled', false),
        ]);
    }

    /**
     * Live cost of a proposed quantity, for the summary panel.
     *
     * Read-only: it changes nothing at Stripe and nothing locally, so the
     * customer can dial the number up and down before committing.
     */
    public function preview(Request $request, Client $client): JsonResponse
    {
        $this->authorizeOwner($request, $client);

        $data = $request->validate([
            'addon'    => ['required', 'string', 'max:100'],
            'quantity' => ['required', 'integer', 'min:0', 'max:999'],
        ]);

        try {
            return response()->json(
                $this->addons->preview($client, $data['addon'], (int) $data['quantity'])
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        $this->authorizeOwner($request, $client);

        if (! config('billing.checkout.enabled', false)) {
            return back()->with('info', 'Add-ons aren’t available to buy just yet.');
        }

        $data = $request->validate([
            'addon'    => ['required', 'string', 'max:100'],
            'quantity' => ['required', 'integer', 'min:0', 'max:999'],
        ]);

        try {
            $result = $this->addons->setQuantity($client, $data['addon'], (int) $data['quantity']);

            AuditLog::record('billing.addon.changed', [
                'payload' => [
                    'client_id' => $client->id,
                    'addon'     => $data['addon'],
                    'quantity'  => $data['quantity'],
                ],
            ]);

            if ($result === null) {
                return back()->with('success', 'Add-on removed. Your next invoice is credited for the unused part.');
            }

            $name = $result->plan?->name ?? 'Add-on';

            return back()->with('success', sprintf(
                '%s × %d — %s per %s. The difference is prorated on your next invoice.',
                $name,
                $result->quantity,
                $result->formattedLineTotal(),
                $result->interval === 'annually' ? 'year' : 'month',
            ));
        } catch (\Stripe\Exception\CardException $e) {
            return back()->with('error', $e->getError()->message ?? 'Your card was declined.');
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('billing.addon.failed', [
                'client_id' => $client->id,
                'addon'     => $data['addon'],
                'error'     => $e->getMessage(),
            ]);

            return back()->with('error', 'We couldn’t update that add-on. Please try again.');
        }
    }

    private function authorizeOwner(Request $request, Client $client): void
    {
        abort_unless(
            $request->user()?->isOwnerOf($client->id),
            403,
            'Only the workspace owner can buy add-ons.'
        );
    }
}
