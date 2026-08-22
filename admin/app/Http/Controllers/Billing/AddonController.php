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

        // Only a workspace with no plan at all is sent away. Anyone on a plan
        // sees the add-ons.
        //
        // This used to bounce anyone without a LIVE STRIPE subscription to the
        // plan ladder with "choose a plan first" — which is what a customer on
        // Growth was told when they clicked Add-ons, because their subscription
        // was merely not yet active at Stripe. Being shown the plan ladder in
        // answer to "I need one more seat", and told to pick the plan you are
        // already paying for, is the whole problem.
        if (! $subscription || $subscription->isFree()) {
            return redirect()
                ->route('billing.plans', ['client' => $client->slug])
                ->with('info', 'Add-ons sit on top of a paid plan — pick one and they unlock.');
        }

        // Whether the purchase can COMPLETE is a separate question from whether
        // the page should render. An add-on is a line on a Stripe subscription,
        // so there has to be one; when there is not, the page still shows the
        // prices and the arithmetic and says precisely what is missing.
        $canBuy = $subscription->stripe_subscription_ref && $subscription->grantsAccess();

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
            'canBuy'       => $canBuy,
            // Named rather than generic, because each of these has a different
            // next step and "unavailable" tells the customer none of them.
            // Each of these says what HAPPENED and what to do about it. The
            // previous wording — "add-ons unlock once your subscription is
            // active, currently awaiting payment" — was accurate and told the
            // customer nothing: it named a state without naming its cause or its
            // remedy, so the only possible reaction was to wonder what it meant.
            'blockedWhy'   => $canBuy ? null : match (true) {
                // A plan a super admin assigned at no charge has no Stripe
                // subscription by design, so an add-on has nothing to attach to
                // and never will. Saying "we are finishing it off" here would be
                // simply untrue — nothing is pending. Extra capacity on a
                // complimentary plan is granted, not sold, and the person who
                // granted the plan can grant that too.
                data_get($subscription->metadata, 'assigned_by_super_admin')
                    => $request->user()?->isSuperAdmin()
                        ? 'This workspace is on a complimentary plan, so it has no Stripe '
                        . 'subscription for a paid add-on to attach to. Grant the capacity instead, '
                        . 'or take a real subscription on the plans page to buy add-ons normally.'
                        : 'Your plan is on us, so there is no subscription for a paid add-on to '
                        . 'attach to. Extra seats or agents can be added to it directly — just ask '
                        . 'and we will raise your allowance.',

                ! $subscription->stripe_subscription_ref
                    => 'This plan hasn’t been set up with our payment provider yet, so there is no '
                     . 'subscription for an add-on to attach to. Nothing for you to do — we are '
                     . 'finishing it off.',

                // Stripe's `incomplete`: the subscription was created but its
                // first payment never succeeded, so it never started. Stripe
                // voids that invoice after about a day, which is why the invoice
                // list shows one marked "voided" — that is the abandoned attempt,
                // not a charge that was taken and reversed.
                $subscription->status === \App\Models\Billing\Subscription::STATUS_INCOMPLETE
                    => 'The first payment for ' . ($subscription->plan?->name ?? 'this plan')
                     . ' was never completed, so the subscription never started — that is also why '
                     . 'you will see a voided invoice. Nothing was charged. Start the plan again and '
                     . 'add-ons unlock immediately.',

                $subscription->isPastDue()
                    => 'Your last payment didn’t go through. Update your card and add-ons are '
                     . 'available again straight away.',

                default
                    => 'Add-ons attach to an active subscription, and this one is currently '
                     . lcfirst($subscription->statusLabel()) . '. Once it is running they unlock '
                     . 'automatically.',
            },
            // Where the fix lives, when there is one the customer can perform.
            'blockedAction' => $canBuy ? null : match (true) {
                // On a complimentary plan the fix is an operator action, so
                // there is normally nothing for the customer to click. The
                // exception is a viewer who IS that operator — on a
                // self-hosted install the owner and the super admin are
                // routinely the same person, and sending them to "just ask"
                // when they are the one being asked is a loop.
                data_get($subscription->metadata, 'assigned_by_super_admin')
                    => $request->user()?->isSuperAdmin()
                        ? [route('ops.billing.workspaces.show', $client->id), 'Raise the allowance']
                        : null,
                $subscription->isPastDue() => ['#payment-methods', 'Update your card'],
                $subscription->status === \App\Models\Billing\Subscription::STATUS_INCOMPLETE
                    => [route('billing.plans', ['client' => $client->slug]), 'Start the plan again'],
                default => null,
            },
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
