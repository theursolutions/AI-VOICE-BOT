<?php

namespace Database\Seeders;

use App\Models\Billing\Feature;
use App\Models\Billing\Plan;
use App\Models\Billing\PlanFeature;
use App\Models\Billing\PlanPrice;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Installs the APPROVED pricing structure.
 *
 * Free (7 days, no card, web chat only) · Starter $19 · Growth $59 ★ ·
 * Scale $149 · Enterprise (contact us). Monthly + annual ("2 months free").
 *
 * These values are a STARTING POINT, not a source of truth. Once seeded, every
 * price, limit, feature, badge and trial length is edited by a super-admin at
 * /admin/billing/plans with no deploy. Re-running this seeder does NOT
 * overwrite an existing plan's prices or limits — see the updateOrCreate keys —
 * so it can't undo an operator's pricing decisions.
 *
 * Stripe objects are NOT created here. Run `php artisan billing:sync-stripe`
 * (or the "Sync all to Stripe" button) once your Stripe keys are set, so the
 * seeder stays runnable offline and in CI.
 */
class BillingSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $features = $this->seedFeatures();
            $this->seedPlans($features);
            $this->seedAddonGrants($features);
        });

        $this->command?->info('Billing catalogue seeded.');
        $this->command?->warn('Next: php artisan billing:sync-stripe  (creates the Stripe Products/Prices)');
    }

    // ── Features ─────────────────────────────────────────────────────

    /**
     * The feature catalogue.
     *
     * `module` binds a feature to an admin module from config/modules.php, so
     * EnsurePlanFeature gates the matching routes. `metric` binds it to a usage
     * meter from config/billing.php, so UsageLimitService enforces it as a
     * quota. Features with neither are display-only marketing lines.
     *
     * NOTE the two separate voice meters: `telephony_minutes` is a real phone
     * call (Twilio rental + carrier per-minute — zero on Free), while
     * `voice_messages` is a mic message in the web widget running on local
     * Whisper + XTTS at near-zero cost, which is why Free can include it.
     *
     * @return array<string,Feature>
     */
    private function seedFeatures(): array
    {
        $definitions = [
            // ── Volume (metered) ─────────────────────────────────────
            ['key' => 'conversations', 'name' => 'AI conversations', 'group' => 'Volume',
             'type' => 'numeric', 'unit' => 'per month', 'metric' => 'conversations',
             'headline' => true, 'sort' => 10,
             'desc' => 'A session with at least one AI reply, on any text channel.'],

            ['key' => 'telephony_minutes', 'name' => 'Phone call minutes', 'group' => 'Volume',
             'type' => 'numeric', 'unit' => 'per month', 'metric' => 'telephony_minutes',
             'headline' => true, 'sort' => 20,
             'desc' => 'Inbound/outbound phone minutes. Zero on the free plan — carrier minutes cost real money.'],

            ['key' => 'voice_messages', 'name' => 'Widget voice messages', 'group' => 'Volume',
             'type' => 'numeric', 'unit' => 'per month', 'metric' => 'voice_messages',
             'headline' => false, 'sort' => 30,
             'desc' => 'Spoken messages in the web chat widget. Runs on local speech models, so it is cheap enough to include free.'],

            ['key' => 'projects', 'name' => 'Projects', 'group' => 'Volume',
             'type' => 'numeric', 'unit' => '', 'headline' => false, 'sort' => 40,
             'desc' => 'Each project gets its own isolated database.'],

            ['key' => 'seats', 'name' => 'Team seats', 'group' => 'Volume',
             'type' => 'numeric', 'unit' => '', 'headline' => true, 'sort' => 50],

            ['key' => 'agents', 'name' => 'AI agents', 'group' => 'Volume',
             'type' => 'numeric', 'unit' => '', 'headline' => false, 'sort' => 60],

            ['key' => 'phone_numbers', 'name' => 'Phone numbers', 'group' => 'Volume',
             'type' => 'numeric', 'unit' => '', 'headline' => false, 'sort' => 70],

            ['key' => 'data_sources', 'name' => 'Data sources', 'group' => 'Volume',
             'type' => 'numeric', 'unit' => '', 'headline' => false, 'sort' => 80],

            ['key' => 'indexed_pages', 'name' => 'Indexed pages', 'group' => 'Volume',
             'type' => 'numeric', 'unit' => 'pages', 'metric' => 'indexed_pages',
             'headline' => false, 'sort' => 90],

            ['key' => 'history_days', 'name' => 'Conversation history', 'group' => 'Volume',
             'type' => 'numeric', 'unit' => 'days', 'headline' => false, 'sort' => 100],

            // ── Always included (Bucket 1) ───────────────────────────
            ['key' => 'voice_cloning', 'name' => 'Voices — 30 stock voices + voice cloning', 'group' => 'Included on every plan',
             'type' => 'boolean', 'module' => 'voices', 'headline' => true, 'sort' => 200,
             'desc' => 'Clone a voice from a 10-second sample. Competitors do not sell this at any price.'],

            ['key' => 'multi_language', 'name' => 'Replies in 13 languages', 'group' => 'Included on every plan',
             'type' => 'boolean', 'headline' => true, 'sort' => 210,
             'desc' => 'Auto-detects the customer’s language. Some competitors charge $99/mo for one extra language.'],

            ['key' => 'lead_capture', 'name' => 'Automatic lead capture → CRM', 'group' => 'Included on every plan',
             'type' => 'boolean', 'module' => 'leads', 'headline' => true, 'sort' => 220],

            ['key' => 'web_widget', 'name' => 'Website chat widget', 'group' => 'Included on every plan',
             'type' => 'boolean', 'module' => 'widget', 'headline' => true, 'sort' => 230],

            ['key' => 'knowledge_base', 'name' => 'Train on your site + documents', 'group' => 'Included on every plan',
             'type' => 'boolean', 'module' => 'data_sources', 'headline' => true, 'sort' => 240],

            ['key' => 'transcripts', 'name' => 'Transcripts & summaries', 'group' => 'Included on every plan',
             'type' => 'boolean', 'module' => 'conversations', 'headline' => false, 'sort' => 250],

            // ── The eight gates (Bucket 3) ───────────────────────────
            ['key' => 'telephony', 'name' => 'Phone calls (voice agent)', 'group' => 'Channels & power features',
             'type' => 'boolean', 'module' => 'telephony', 'headline' => true, 'sort' => 300,
             'desc' => 'Gate #1. Off on Free — this single line is the biggest protector of gross margin.'],

            ['key' => 'channels_meta', 'name' => 'WhatsApp, Instagram & Facebook', 'group' => 'Channels & power features',
             'type' => 'numeric', 'unit' => 'channels', 'module' => 'channels',
             'headline' => true, 'sort' => 310,
             'desc' => 'Gate #2. Free: none. Starter: WhatsApp + 1. Growth+: all.'],

            ['key' => 'shared_inbox', 'name' => 'Shared team inbox', 'group' => 'Channels & power features',
             'type' => 'boolean', 'module' => 'messages', 'headline' => false, 'sort' => 320],

            ['key' => 'flow_builder', 'name' => 'Visual flow builder', 'group' => 'Channels & power features',
             'type' => 'numeric', 'unit' => 'flows', 'module' => 'flows',
             'headline' => false, 'sort' => 330,
             'desc' => 'Gate #3. Free: none. Starter: 1. Growth+: unlimited.'],

            ['key' => 'team_roles', 'name' => 'Custom roles & permissions', 'group' => 'Channels & power features',
             'type' => 'boolean', 'module' => 'team', 'headline' => false, 'sort' => 340,
             'desc' => 'Gate #4. Growth+.'],

            ['key' => 'api_access', 'name' => 'API access + webhooks', 'group' => 'Channels & power features',
             'type' => 'boolean', 'headline' => false, 'sort' => 350,
             'desc' => 'Gate #5. Growth+. A well-known competitor gates this at $120/mo.'],

            ['key' => 'database_connector', 'name' => 'Live database + per-table AI access control', 'group' => 'Channels & power features',
             'type' => 'boolean', 'headline' => true, 'sort' => 360,
             'desc' => 'Gate #6. Growth+. No competitor in our research sells this at any price — the strongest upgrade lever.'],

            ['key' => 'remove_branding', 'name' => 'Remove “Powered by” badge', 'group' => 'Channels & power features',
             'type' => 'boolean', 'headline' => false, 'sort' => 370,
             'desc' => 'Gate #7. Growth+ (a paid add-on on Starter). A competitor charges $1,188/yr for this.'],

            ['key' => 'white_label', 'name' => 'White-label + custom domain', 'group' => 'Channels & power features',
             'type' => 'boolean', 'headline' => false, 'sort' => 380,
             'desc' => 'Gate #8a. Scale only.'],

            ['key' => 'byo_llm', 'name' => 'Brain Settings — bring your own AI keys / local model', 'group' => 'Channels & power features',
             'type' => 'boolean', 'module' => 'brain_settings', 'headline' => false, 'sort' => 390,
             'desc' => 'Gate #8b. Scale only — it also keeps the customers most likely to want expensive models on the top tier.'],

            ['key' => 'audit_export', 'name' => 'Audit log export', 'group' => 'Channels & power features',
             'type' => 'boolean', 'headline' => false, 'sort' => 400,
             'desc' => 'Gate #8c. Scale only.'],

            ['key' => 'bot_strategy', 'name' => 'Bot knowledge strategy', 'group' => 'Channels & power features',
             'type' => 'boolean', 'module' => 'bot_strategy', 'headline' => false, 'sort' => 335,
             'desc' => 'Choose which data tiers the bot may draw on when answering.'],

            ['key' => 'skills', 'name' => 'Skills & multi-agent routing', 'group' => 'Channels & power features',
             'type' => 'boolean', 'module' => 'skills', 'headline' => false, 'sort' => 336,
             'desc' => 'Route conversations to the right agent by skill, with a library of prebuilt actions.'],

            ['key' => 'assistant_access', 'name' => 'Team Assistant (in-app AI)', 'group' => 'Channels & power features',
             'type' => 'boolean', 'module' => 'assistant', 'headline' => false, 'sort' => 345,
             'desc' => 'Ask-AI inside the admin. Every question costs LLM tokens, so it is not on the free plan.'],

            ['key' => 'crm_connectors', 'name' => 'CRM connectors (HubSpot, Salesforce, Pipedrive, Zoho)', 'group' => 'Channels & power features',
             'type' => 'boolean', 'headline' => true, 'sort' => 365,
             'desc' => 'Two-way sync with an existing CRM. Enforced when starting a crm_oauth connection.'],

            // ── Support / commercial ─────────────────────────────────
            ['key' => 'support', 'name' => 'Support', 'group' => 'Support',
             'type' => 'text', 'headline' => false, 'sort' => 500],

            ['key' => 'overage_voice', 'name' => 'Extra phone minutes', 'group' => 'Support',
             'type' => 'text', 'headline' => false, 'sort' => 510,
             'desc' => 'Published overage rate. Paid plans keep working past the allowance — an AI receptionist that stops answering mid-month is worse for the customer than a slightly larger invoice.'],

            ['key' => 'sso', 'name' => 'SSO, DPA & custom SLA', 'group' => 'Support',
             'type' => 'boolean', 'headline' => false, 'sort' => 520],
        ];

        $out = [];

        foreach ($definitions as $def) {
            $out[$def['key']] = Feature::updateOrCreate(
                ['key' => $def['key']],
                [
                    'name'        => $def['name'],
                    'description' => $def['desc'] ?? null,
                    'value_type'  => $def['type'],
                    'unit'        => $def['unit'] ?? null,
                    'module_key'  => $def['module'] ?? null,
                    'metric_key'  => $def['metric'] ?? null,
                    'group'       => $def['group'],
                    'sort_order'  => $def['sort'],
                    'is_visible'  => true,
                    'is_headline' => (bool) ($def['headline'] ?? false),
                ]
            );
        }

        return $out;
    }

    // ── Plans ────────────────────────────────────────────────────────

    /** @param  array<string,Feature>  $features */
    private function seedPlans(array $features): void
    {
        // value: null = NOT granted (no row written) · '-1' = unlimited
        $plans = [
            [
                'slug' => 'free', 'name' => 'Free', 'type' => 'free',
                'tagline' => 'Try it on your own data for 7 days. No card.',
                'sort' => 0, 'cta' => 'Start free',
                'free_window_days' => 7, 'trial_days' => 0,
                'prices' => [],
                'values' => [
                    'conversations' => '100', 'telephony_minutes' => null, 'voice_messages' => '50',
                    'projects' => '1', 'seats' => '2', 'agents' => '1',
                    'phone_numbers' => null, 'data_sources' => '1', 'indexed_pages' => '50',
                    'history_days' => '7',
                    'voice_cloning' => null, 'multi_language' => '1', 'lead_capture' => '1',
                    'web_widget' => '1', 'knowledge_base' => '1', 'transcripts' => '1',
                    'telephony' => null, 'channels_meta' => null, 'shared_inbox' => null,
                    'flow_builder' => null, 'team_roles' => null, 'api_access' => null,
                    'database_connector' => null, 'remove_branding' => null,
                    'white_label' => null, 'byo_llm' => null, 'audit_export' => null,
                    'bot_strategy' => null, 'assistant_access' => null, 'crm_connectors' => null, 'skills' => null,
                    'support' => 'Community', 'overage_voice' => null, 'sso' => null,
                ],
            ],
            [
                'slug' => 'starter', 'name' => 'Starter', 'type' => 'standard',
                'tagline' => 'For a single business that wants to stop missing calls.',
                'sort' => 1, 'cta' => 'Get started',
                'free_window_days' => null, 'trial_days' => 0,
                // monthly cents, annual cents ("2 months free")
                'prices' => ['monthly' => 1900, 'annually' => 19000],
                'values' => [
                    'conversations' => '1000', 'telephony_minutes' => '60', 'voice_messages' => '500',
                    'projects' => '1', 'seats' => '3', 'agents' => '2',
                    'phone_numbers' => '1', 'data_sources' => '3', 'indexed_pages' => '500',
                    'history_days' => '30',
                    'voice_cloning' => '1', 'multi_language' => '1', 'lead_capture' => '1',
                    'web_widget' => '1', 'knowledge_base' => '1', 'transcripts' => '1',
                    'telephony' => '1', 'channels_meta' => '2', 'shared_inbox' => '1',
                    'flow_builder' => '1', 'team_roles' => null, 'api_access' => null,
                    'database_connector' => null, 'remove_branding' => null,
                    'white_label' => null, 'byo_llm' => null, 'audit_export' => null,
                    'bot_strategy' => '1', 'assistant_access' => '1', 'crm_connectors' => null, 'skills' => '1',
                    'support' => 'Email', 'overage_voice' => '$0.35/min', 'sso' => null,
                ],
            ],
            [
                'slug' => 'growth', 'name' => 'Growth', 'type' => 'standard',
                'tagline' => 'Every channel, one inbox — for about half what three separate tools cost.',
                'sort' => 2, 'cta' => 'Get started',
                'featured' => true, 'badge' => 'Most popular',
                'free_window_days' => null, 'trial_days' => 0,
                'prices' => ['monthly' => 5900, 'annually' => 59000],
                'values' => [
                    'conversations' => '5000', 'telephony_minutes' => '300', 'voice_messages' => '3000',
                    'projects' => '3', 'seats' => '10', 'agents' => '10',
                    'phone_numbers' => '3', 'data_sources' => '-1', 'indexed_pages' => '5000',
                    'history_days' => '-1',
                    'voice_cloning' => '1', 'multi_language' => '1', 'lead_capture' => '1',
                    'web_widget' => '1', 'knowledge_base' => '1', 'transcripts' => '1',
                    'telephony' => '1', 'channels_meta' => '-1', 'shared_inbox' => '1',
                    'flow_builder' => '-1', 'team_roles' => '1', 'api_access' => '1',
                    'database_connector' => '1', 'remove_branding' => '1',
                    'white_label' => null, 'byo_llm' => null, 'audit_export' => null,
                    'bot_strategy' => '1', 'assistant_access' => '1', 'crm_connectors' => '1', 'skills' => '1',
                    'support' => 'Priority email', 'overage_voice' => '$0.30/min', 'sso' => null,
                ],
            ],
            [
                'slug' => 'scale', 'name' => 'Scale', 'type' => 'standard',
                'tagline' => 'Multi-location, agencies, and teams with compliance requirements.',
                'sort' => 3, 'cta' => 'Get started',
                'free_window_days' => null, 'trial_days' => 0,
                'prices' => ['monthly' => 14900, 'annually' => 149000],
                'values' => [
                    'conversations' => '20000', 'telephony_minutes' => '1200', 'voice_messages' => '-1',
                    'projects' => '10', 'seats' => '25', 'agents' => '-1',
                    'phone_numbers' => '10', 'data_sources' => '-1', 'indexed_pages' => '25000',
                    'history_days' => '-1',
                    'voice_cloning' => '1', 'multi_language' => '1', 'lead_capture' => '1',
                    'web_widget' => '1', 'knowledge_base' => '1', 'transcripts' => '1',
                    'telephony' => '1', 'channels_meta' => '-1', 'shared_inbox' => '1',
                    'flow_builder' => '-1', 'team_roles' => '1', 'api_access' => '1',
                    'database_connector' => '1', 'remove_branding' => '1',
                    'white_label' => '1', 'byo_llm' => '1', 'audit_export' => '1',
                    'bot_strategy' => '1', 'assistant_access' => '1', 'crm_connectors' => '1', 'skills' => '1',
                    'support' => 'Priority + onboarding', 'overage_voice' => '$0.25/min', 'sso' => null,
                ],
            ],
            [
                'slug' => 'enterprise', 'name' => 'Enterprise', 'type' => 'enterprise',
                'tagline' => 'Unlimited projects, SSO, dedicated infrastructure and a custom SLA.',
                'sort' => 4, 'cta' => 'Talk to us', 'cta_url' => '/contact',
                'free_window_days' => null, 'trial_days' => 0,
                'prices' => [],
                'values' => [
                    'conversations' => '-1', 'telephony_minutes' => '-1', 'voice_messages' => '-1',
                    'projects' => '-1', 'seats' => '-1', 'agents' => '-1',
                    'phone_numbers' => '-1', 'data_sources' => '-1', 'indexed_pages' => '-1',
                    'history_days' => '-1',
                    'voice_cloning' => '1', 'multi_language' => '1', 'lead_capture' => '1',
                    'web_widget' => '1', 'knowledge_base' => '1', 'transcripts' => '1',
                    'telephony' => '1', 'channels_meta' => '-1', 'shared_inbox' => '1',
                    'flow_builder' => '-1', 'team_roles' => '1', 'api_access' => '1',
                    'database_connector' => '1', 'remove_branding' => '1',
                    'white_label' => '1', 'byo_llm' => '1', 'audit_export' => '1',
                    'bot_strategy' => '1', 'assistant_access' => '1', 'crm_connectors' => '1', 'skills' => '1',
                    'support' => 'Dedicated CSM + SLA', 'overage_voice' => 'Contract rate', 'sso' => '1',
                ],
            ],
        ];

        foreach ($plans as $spec) {
            // Keyed on slug and NON-destructive: an existing plan keeps the
            // name/copy/badge an operator has since edited. Re-running the
            // seeder must never quietly revert someone's pricing decisions.
            $plan = Plan::firstOrCreate(
                ['slug' => $spec['slug']],
                [
                    'name'             => $spec['name'],
                    'type'             => $spec['type'],
                    'tagline'          => $spec['tagline'],
                    'sort_order'       => $spec['sort'],
                    'cta_label'        => $spec['cta'],
                    'cta_url'          => $spec['cta_url'] ?? null,
                    'badge'            => $spec['badge'] ?? null,
                    'is_featured'      => (bool) ($spec['featured'] ?? false),
                    'is_active'        => true,
                    'is_public'        => true,
                    'trial_days'       => $spec['trial_days'],
                    'trial_requires_payment_method' => true,
                    'free_window_days' => $spec['free_window_days'],
                ]
            );

            // Prices: only ever ADDED when the interval has none. Never
            // overwritten — an existing price may already be live in Stripe
            // with subscribers attached to it.
            foreach ($spec['prices'] as $interval => $cents) {
                $exists = PlanPrice::query()
                    ->where('plan_id', $plan->id)
                    ->where('interval', $interval)
                    ->exists();

                if ($exists) {
                    continue;
                }

                PlanPrice::create([
                    'plan_id'        => $plan->id,
                    'interval'       => $interval,
                    'currency'       => 'usd',
                    'unit_amount'    => $cents,
                    'is_active'      => true,
                    'effective_from' => now(),
                ]);
            }

            // Feature values: only seeded for a plan that has none yet, so an
            // operator's edits to limits survive a re-seed.
            if (PlanFeature::query()->where('plan_id', $plan->id)->exists()) {
                continue;
            }

            foreach ($spec['values'] as $key => $value) {
                // null = not granted at all → deliberately write no row.
                if ($value === null) {
                    continue;
                }

                if (! isset($features[$key])) {
                    continue;
                }

                PlanFeature::create([
                    'plan_id'    => $plan->id,
                    'feature_id' => $features[$key]->id,
                    'value'      => (string) $value,
                    'sort_order' => $features[$key]->sort_order,
                ]);
            }
        }
    }

    // ── Add-ons ──────────────────────────────────────────────────────

    /**
     * What ONE unit of each add-on grants.
     *
     * The add-on PLANS and their prices are created by migration
     * 2026_08_16_110010, but the `features` table is populated HERE — so on a
     * fresh install (`migrate` then `db:seed`) that migration runs against an
     * empty feature catalogue, finds no `seats` row, and writes no grant. The
     * add-on would then be perfectly billable and grant nothing: the customer
     * pays $5/mo for a seat that never appears. This closes that window, and
     * is where the mapping belongs anyway — the seeder owns features.
     *
     * Idempotent, and never overwrites an existing value: an operator who
     * changes "extra seat" to grant 2 keeps their decision through a re-seed.
     *
     * @param array<string,Feature> $features
     */
    private function seedAddonGrants(array $features): void
    {
        $grants = [
            'addon-seat'  => ['seats' => 1],
            'addon-agent' => ['agents' => 1],
        ];

        foreach ($grants as $slug => $values) {
            $plan = Plan::query()->where('slug', $slug)->first();

            if (! $plan) {
                continue;   // migration hasn't run / add-on was retired
            }

            foreach ($values as $key => $value) {
                if (! isset($features[$key])) {
                    continue;
                }

                PlanFeature::firstOrCreate(
                    ['plan_id' => $plan->id, 'feature_id' => $features[$key]->id],
                    ['value' => (string) $value, 'sort_order' => 0],
                );
            }
        }
    }
}
