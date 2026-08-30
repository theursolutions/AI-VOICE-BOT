<?php

namespace App\Http\Controllers\SuperAdmin\Billing;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Billing\Feature;
use App\Models\Billing\Plan;
use App\Models\Billing\PlanGrant;
use App\Models\Client;
use App\Services\Billing\WorkspacePlanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Put a workspace on any plan, and give it whatever it needs.
 *
 * The operator counterpart to the customer's billing page: everything a sales
 * conversation ends up promising — a pilot on Scale for a month, two extra seats,
 * 40,000 messages on a Starter price — done here instead of in a database client,
 * with a record of who agreed to it.
 *
 * Every action is audit-logged, and every one carries a note field, because the
 * question asked six months later is never "what is this workspace allowed" (the
 * page answers that) but "why".
 */
class WorkspacePlansController extends Controller
{
    public function __construct(private readonly WorkspacePlanService $workspaces)
    {
    }

    /** Workspaces, newest first, with what they are on. */
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));

        $clients = Client::query()
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$search}%")
                ->orWhere('slug', 'like', "%{$search}%")))
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        // Grant counts in one query rather than per row.
        $grantCounts = PlanGrant::query()
            ->inForce()
            ->selectRaw('client_id, COUNT(*) as n')
            ->groupBy('client_id')
            ->pluck('n', 'client_id');

        return view('ops.billing.workspaces.index', [
            'title'       => 'Workspace plans',
            'clients'     => $clients,
            'grantCounts' => $grantCounts,
            'filters'     => ['q' => $search],
        ]);
    }

    public function show(Request $request, int $id): View
    {
        $client = Client::findOrFail($id);

        return view('ops.billing.workspaces.show', [
            'title'        => $client->name,
            'client'       => $client,
            'subscription' => $client->currentSubscription(),
            'plan'         => $client->currentPlan(),
            'plans'        => Plan::query()->where('is_active', true)->ordered()->get(),
            'rows'         => $this->workspaces->entitlementRows($client),
            'grants'       => $this->workspaces->grantsFor($client),
            'grantable'    => Feature::query()->orderBy('group')->orderBy('sort_order')->get(),
        ]);
    }

    /** Move a workspace onto a plan at no charge. */
    public function assign(Request $request, int $id): RedirectResponse
    {
        $client = Client::findOrFail($id);

        $data = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'note'    => ['nullable', 'string', 'max:500'],
        ]);

        $plan = Plan::findOrFail($data['plan_id']);

        try {
            $this->workspaces->assignFree($client, $plan, $request->user(), $data['note'] ?? null);
        } catch (\RuntimeException $e) {
            // The live-Stripe-subscription refusal. Surfaced verbatim: it names
            // the exact thing the operator has to do first.
            return back()->with('error', $e->getMessage());
        }

        AuditLog::record('billing.workspace.plan_assigned', [
            'target_type' => 'client',
            'target_id'   => $client->id,
            'payload'     => ['plan' => $plan->slug, 'free' => true, 'note' => $data['note'] ?? null],
        ]);

        return back()->with('success', "{$client->name} is now on {$plan->name}, at no charge.");
    }

    /** Grant or update one allowance. */
    public function grant(Request $request, int $id): RedirectResponse
    {
        $client = Client::findOrFail($id);

        $data = $request->validate([
            'feature_key' => ['required', 'string', 'exists:features,key'],
            // Signed, and -1 is the unlimited sentinel. Bounded well above any
            // real allowance but short of a number that would overflow when
            // added to a plan limit.
            'value'       => ['required', 'integer', 'min:-1', 'max:100000000'],
            'note'        => ['nullable', 'string', 'max:500'],
            'expires_at'  => ['nullable', 'date', 'after:now'],
        ], [
            'expires_at.after' => 'An expiry in the past would take effect immediately — leave it '
                                . 'blank for a grant that does not lapse.',
        ]);

        $grant = $this->workspaces->grant(
            $client,
            $data['feature_key'],
            (int) $data['value'],
            $request->user(),
            $data['note'] ?? null,
            $data['expires_at'] ? new \DateTimeImmutable($data['expires_at']) : null,
        );

        AuditLog::record('billing.workspace.granted', [
            'target_type' => 'client',
            'target_id'   => $client->id,
            'payload'     => [
                'feature' => $data['feature_key'],
                'value'   => (int) $data['value'],
                'expires' => $data['expires_at'] ?? null,
                'note'    => $data['note'] ?? null,
            ],
        ]);

        return back()->with('success', $grant
            ? 'Granted. It applies to their next message.'
            : 'Grant removed.');
    }

    public function revoke(Request $request, int $id, string $featureKey): RedirectResponse
    {
        $client = Client::findOrFail($id);

        $this->workspaces->revoke($client, $featureKey);

        AuditLog::record('billing.workspace.grant_revoked', [
            'target_type' => 'client',
            'target_id'   => $client->id,
            'payload'     => ['feature' => $featureKey],
        ]);

        return back()->with('success', 'Grant removed.');
    }
}
