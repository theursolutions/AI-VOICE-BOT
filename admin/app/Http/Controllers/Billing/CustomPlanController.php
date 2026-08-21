<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Client;
use App\Services\Billing\ConversationPricer;
use App\Services\Billing\CustomPlanService;
use App\Services\Billing\PricingPresenter;
use App\Services\Conversation\ConversationBudget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Build your own plan.
 *
 * For the workspace whose shape none of the published tiers fits — 400
 * conversations but twelve people, or 8,000 conversations and one. Rather than
 * pushing them to the tier above and hoping, they configure what they need and
 * are quoted from measured cost plus a margin that stays inside the band the
 * published tiers were approved against.
 *
 * QUOTING AND BUYING ARE SEPARATE ENDPOINTS, and the quote is recomputed
 * server-side when the plan is built. The browser never sends a price — it sends
 * a configuration and receives a price, and the figure it was shown is
 * advisory. Trusting a posted amount would let anyone name their own, and the
 * only reason a client-side price exists at all is so the page can update
 * without a round trip per keystroke.
 */
class CustomPlanController extends Controller
{
    public function __construct(
        private readonly ConversationPricer $pricer,
        private readonly CustomPlanService $plans,
        private readonly PricingPresenter $presenter,
    ) {
    }

    /**
     * Bounds on what can be configured.
     *
     * Both ends matter. The floors keep the calculator from quoting a plan too
     * small to be worth supporting; the ceilings are where a configuration stops
     * being self-serve and should be a conversation — at the top of these ranges
     * a single workspace is a material share of our inference bill, and that is
     * a deal to negotiate rather than a form to submit.
     */
    private const BOUNDS = [
        'conversations'            => [50, 20_000],
        'replies_per_conversation' => [ConversationBudget::MIN_LIMIT, ConversationBudget::MAX_LIMIT],
        'seats'                    => [1, 100],
        'agents'                   => [1, 50],
        'phone_numbers'            => [0, 25],
        'phone_minutes'            => [0, 10_000],
    ];

    public function show(Request $request, Client $client): View
    {
        $this->authorizeOwner($request, $client);

        $existing = $this->plans->currentFor($client);

        // Seed the form from a plan they already configured, so reopening the
        // page shows what they chose rather than starting from defaults.
        $config = $existing
            ? (array) data_get($existing->metadata, 'quote', [])
            : [];

        return view('billing.custom', [
            'title'    => 'Build your own plan',
            'client'   => $client,
            'existing' => $existing,
            'bounds'   => self::BOUNDS,
            'defaults' => [
                'conversations'            => (int) ($config['conversations'] ?? 1_000),
                'replies_per_conversation' => (int) ($config['replies'] ?? ConversationBudget::DEFAULT_LIMIT),
                'seats'                    => (int) ($config['seats'] ?? 3),
                'agents'                   => (int) ($config['agents'] ?? 2),
                'phone_numbers'            => (int) ($config['phone_numbers'] ?? 0),
                'phone_minutes'            => (int) ($config['phone_minutes'] ?? 0),
            ],
            // Whether the quote is priced from real usage or from our published
            // estimate. Stated rather than implied — they are different claims.
            'basis'    => $this->pricer->costPerMessage()['basis'],
        ]);
    }

    /**
     * Live quote for the form. Returns the price and how it was reached.
     *
     * The whole breakdown goes back, not just the number. A configurator that
     * shows a total and hides its arithmetic reads as arbitrary, and the first
     * question anyone asks of a generated price is why it is that much.
     */
    public function quote(Request $request, Client $client): JsonResponse
    {
        $this->authorizeOwner($request, $client);

        $config = $this->validated($request);
        $quote  = $this->pricer->quote($config);

        return response()->json([
            'ok'        => true,
            'quote'     => $quote,
            // Formatted through the same presenter as every other price on the
            // site, so the local-currency line and the rounding are identical
            // here and on /pricing.
            'formatted' => $this->presenter->renderPrice($quote['price_cents'], 'monthly', $request),
            'annual'    => $this->presenter->renderPrice($quote['price_cents'] * 10, 'annually', $request),
        ]);
    }

    /**
     * Build the plan and send them to checkout.
     *
     * Re-quoted here from the posted CONFIGURATION, never from a posted price.
     */
    public function store(Request $request, Client $client): RedirectResponse
    {
        $this->authorizeOwner($request, $client);

        $config = $this->validated($request);
        $plan   = $this->plans->build($client, $config);
        $price  = $plan->priceFor('monthly');

        AuditLog::record('billing.custom_plan.built', [
            'target_type' => 'plan',
            'target_id'   => $plan->id,
            'payload'     => [
                'client_id' => $client->id,
                'config'    => $config,
                'price'     => $price?->unit_amount,
            ],
        ]);

        // A plan with no Stripe price cannot be bought. Say so plainly rather
        // than sending them to a checkout that will refuse — this is the state
        // right after a rate change or a fresh install, and it is an operator
        // task, not a customer error.
        if (! $price?->stripe_price_ref) {
            return redirect()
                ->route('billing.index', ['client' => $client->slug])
                ->with('info', "“{$plan->name}” is ready. We just need to finish setting it up on our "
                    . 'side before you can subscribe — we will email you shortly.');
        }

        // The checkout page resolves by plan SLUG and interval, not price id.
        return redirect()->route('billing.checkout', [
            'client'   => $client->slug,
            'plan'     => $plan->slug,
            'interval' => 'monthly',
        ]);
    }

    /** @return array<string, int> */
    private function validated(Request $request): array
    {
        $rules = [];

        foreach (self::BOUNDS as $field => [$min, $max]) {
            $rules[$field] = ['required', 'integer', "min:{$min}", "max:{$max}"];
        }

        $data = $request->validate($rules, [
            'conversations.max' => 'At this volume we would rather talk it through — please contact us '
                                 . 'so we can price it properly.',
        ]);

        $data = array_map('intval', $data);

        // Seats and agents are bundled with volume rather than charged for, so
        // the price does not rise with them — which means nothing here stops a
        // posted configuration asking for a hundred seats on the smallest plan
        // and getting them for $9. The cap is the only thing enforcing it, and a
        // rule that exists only in the browser is not a rule.
        $messages = $data['conversations'] * $data['replies_per_conversation'];
        [$maxSeats, $maxAgents] = $this->pricer->allowances($messages);

        $over = [];

        if ($data['seats'] > $maxSeats) {
            $over['seats'] = "This volume includes up to {$maxSeats} seats. Raise the number of "
                . 'conversations for more, or add seats after you subscribe.';
        }

        if ($data['agents'] > $maxAgents) {
            $over['agents'] = "This volume includes up to {$maxAgents} AI agents. Raise the number of "
                . 'conversations for more.';
        }

        if ($over) {
            throw \Illuminate\Validation\ValidationException::withMessages($over);
        }

        return $data;
    }

    private function authorizeOwner(Request $request, Client $client): void
    {
        abort_unless(
            $request->user()?->isOwnerOf($client->id),
            403,
            'Only the workspace owner can build a plan.'
        );
    }
}
