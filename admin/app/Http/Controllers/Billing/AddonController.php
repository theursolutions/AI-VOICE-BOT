<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Client;
use App\Services\Billing\AddonService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
