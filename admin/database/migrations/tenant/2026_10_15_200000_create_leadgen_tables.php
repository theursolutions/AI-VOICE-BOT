<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lead Generation & Outreach (module `leadgen`). See docs/LEADGEN_PLAN.md.
 *
 * Prospects are kept apart from `contacts` on purpose: a search can return
 * thousands of businesses nobody has qualified yet, and the CRM should only
 * hold the ones a person chose to pursue. "Add to CRM" links a prospect to a
 * contact (prospect.contact_id) through ContactResolver.
 *
 * Every table carries project_id even though the database is already per
 * project — the models scope on it too, so a mis-switched tenant connection
 * returns nothing instead of another project's rows.
 *
 * Uniqueness that guards correctness lives in the database, not in PHP,
 * because searches, imports and sends all run from queue workers that can
 * overlap or retry:
 *   - one prospect per (provider, source record)
 *   - one recipient per (campaign, prospect)
 *   - one message per idempotency key (campaign:recipient:step)
 *   - one suppression per (kind, value)
 */
return new class extends Migration
{
    public function up(): void
    {
        $s = Schema::connection('tenant');

        // What the tenant sells, to whom — the context for scoring and drafts.
        // Several per project: an agency runs one per end client.
        $s->create('lg_offer_profiles', function (Blueprint $t) {
            $t->bigInteger('id', true);
            $t->integer('project_id');
            $t->string('name', 120);
            $t->text('product')->nullable();
            $t->json('icp')->nullable();            // ideal customer profile: industries, locations, size, signals
            $t->text('value_prop')->nullable();
            $t->json('exclusions')->nullable();
            $t->json('questions')->nullable();      // qualification questions
            $t->json('scoring')->nullable();        // criterion => weight
            $t->string('sender_name', 120)->nullable();
            $t->string('booking_link', 500)->nullable();
            $t->string('language', 12)->default('en');
            $t->string('tone', 24)->default('professional');
            $t->integer('created_by')->nullable();
            $t->integer('created_at')->nullable();
            $t->integer('updated_at')->nullable();
            $t->integer('deleted_at')->nullable();
            $t->index('project_id', 'lg_op_project');
        });

        $s->create('lg_searches', function (Blueprint $t) {
            $t->bigInteger('id', true);
            $t->integer('project_id');
            $t->unsignedBigInteger('profile_id')->nullable();
            $t->string('name', 160)->nullable();
            $t->string('provider', 40);
            $t->json('criteria');
            // queued | running | paused | cancelling | cancelled | completed | failed
            $t->string('status', 16)->default('queued');
            $t->json('cursor')->nullable();          // provider pagination state, for resume
            $t->json('progress')->nullable();        // fetched / created / duplicates / excluded / errors
            $t->integer('estimate')->nullable();
            $t->boolean('saved')->default(false);    // listed under Saved searches
            $t->text('error')->nullable();
            $t->unsignedBigInteger('rerun_of')->nullable();
            $t->integer('created_by')->nullable();
            $t->integer('started_at')->nullable();
            $t->integer('finished_at')->nullable();
            $t->integer('created_at')->nullable();
            $t->integer('updated_at')->nullable();
            $t->integer('deleted_at')->nullable();
            $t->index(['project_id', 'status'], 'lg_search_status');
            $t->index(['project_id', 'saved'], 'lg_search_saved');
        });

        $s->create('lg_prospects', function (Blueprint $t) {
            $t->bigInteger('id', true);
            $t->integer('project_id');

            $t->string('business_name', 255);
            $t->string('category', 120)->nullable();
            $t->string('website', 500)->nullable();
            $t->string('domain_norm', 191)->nullable();   // registrable domain, lowercase, for dedup
            $t->string('phone_e164', 32)->nullable();
            $t->string('email', 191)->nullable();
            $t->json('address')->nullable();
            $t->string('city', 120)->nullable();
            $t->char('country_code', 2)->nullable();
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();
            $t->json('socials')->nullable();              // [{platform, url}]

            // Where the record came from — kept for attribution and licence duties.
            $t->string('source_provider', 40);
            $t->string('source_record_id', 191);
            $t->string('source_url', 500)->nullable();
            $t->string('source_license', 80)->nullable();
            $t->json('source_meta')->nullable();          // provider-specific extras (release, confidence, taxonomy…)
            $t->integer('discovered_at')->nullable();
            $t->integer('last_verified_at')->nullable();

            // Per-field provenance: {"email": {"source": "website", "url": "...", "found_at": 0,
            //   "verification": "unverified|syntax_ok|mx_ok|found_on_site|user_confirmed"}}
            $t->json('field_meta')->nullable();
            // none | queued | running | done | failed | blocked (robots / unsafe url)
            $t->string('enrichment_status', 12)->default('none');

            // new | reviewing | approved | rejected | in_crm
            $t->string('status', 12)->default('new');
            $t->integer('owner_id')->nullable();
            $t->json('tags')->nullable();
            $t->text('notes')->nullable();
            $t->unsignedBigInteger('contact_id')->nullable();
            $t->unsignedBigInteger('duplicate_of')->nullable();   // "possible duplicate" flag, never auto-merged
            $t->integer('suppressed_at')->nullable();

            $t->integer('created_by')->nullable();
            $t->integer('created_at')->nullable();
            $t->integer('updated_at')->nullable();
            $t->integer('deleted_at')->nullable();

            $t->unique(['project_id', 'source_provider', 'source_record_id'], 'lg_prospect_source_unique');
            $t->index(['project_id', 'domain_norm'], 'lg_prospect_domain');
            $t->index(['project_id', 'phone_e164'], 'lg_prospect_phone');
            $t->index(['project_id', 'email'], 'lg_prospect_email');
            $t->index(['project_id', 'status'], 'lg_prospect_status');
            $t->index(['project_id', 'country_code', 'city'], 'lg_prospect_place');
            $t->index('contact_id', 'lg_prospect_contact');
        });

        // Which prospects a search produced, and what happened to each.
        $s->create('lg_search_results', function (Blueprint $t) {
            $t->bigInteger('id', true);
            $t->integer('project_id');
            $t->unsignedBigInteger('search_id');
            $t->unsignedBigInteger('prospect_id');
            // new | duplicate | existing_crm | excluded
            $t->string('outcome', 16);
            $t->unsignedBigInteger('duplicate_of')->nullable();
            $t->integer('created_at')->nullable();
            $t->unique(['search_id', 'prospect_id'], 'lg_sr_unique');
            $t->index(['project_id', 'search_id', 'outcome'], 'lg_sr_outcome');
        });

        $s->create('lg_lists', function (Blueprint $t) {
            $t->bigInteger('id', true);
            $t->integer('project_id');
            $t->string('name', 160);
            $t->text('description')->nullable();
            $t->integer('created_by')->nullable();
            $t->integer('created_at')->nullable();
            $t->integer('updated_at')->nullable();
            $t->integer('deleted_at')->nullable();
            $t->index('project_id', 'lg_list_project');
        });

        $s->create('lg_list_items', function (Blueprint $t) {
            $t->bigInteger('id', true);
            $t->integer('project_id');
            $t->unsignedBigInteger('list_id');
            $t->unsignedBigInteger('prospect_id');
            $t->integer('added_by')->nullable();
            $t->integer('created_at')->nullable();
            $t->unique(['list_id', 'prospect_id'], 'lg_li_unique');
            $t->index('prospect_id', 'lg_li_prospect');
        });

        // One row per scoring run, so re-scoring keeps history.
        $s->create('lg_qualifications', function (Blueprint $t) {
            $t->bigInteger('id', true);
            $t->integer('project_id');
            $t->unsignedBigInteger('prospect_id');
            $t->unsignedBigInteger('profile_id');
            $t->unsignedTinyInteger('score');                 // 0–100
            $t->string('band', 16);                           // high | medium | low | needs_review
            $t->text('explanation');
            $t->json('evidence')->nullable();                 // [{field, value}] the explanation cites
            $t->json('hypotheses')->nullable();               // possible needs, always labelled as hypotheses
            $t->text('value_prop')->nullable();
            $t->string('suggested_channel', 16)->nullable();
            $t->string('next_action', 255)->nullable();
            $t->string('model', 120)->nullable();
            $t->integer('brain_id')->nullable();
            $t->integer('reviewed_by')->nullable();
            $t->string('override_band', 16)->nullable();
            $t->string('override_reason', 500)->nullable();
            $t->boolean('is_current')->default(true);
            $t->integer('created_at')->nullable();
            $t->integer('updated_at')->nullable();
            $t->index(['project_id', 'prospect_id', 'is_current'], 'lg_q_current');
            $t->index(['project_id', 'profile_id', 'band'], 'lg_q_band');
        });

        $s->create('lg_templates', function (Blueprint $t) {
            $t->bigInteger('id', true);
            $t->integer('project_id');
            $t->string('name', 160);
            $t->string('channel', 16);                        // email | whatsapp | messenger | instagram
            $t->string('kind', 24)->default('intro');         // intro | follow_up | meeting | inquiry
            $t->string('language', 12)->default('en');
            $t->string('subject', 255)->nullable();
            $t->text('body');
            $t->string('wa_template_name', 191)->nullable();  // approved WhatsApp template to send
            $t->string('wa_template_lang', 12)->nullable();
            $t->integer('created_by')->nullable();
            $t->integer('created_at')->nullable();
            $t->integer('updated_at')->nullable();
            $t->integer('deleted_at')->nullable();
            $t->index(['project_id', 'channel'], 'lg_tpl_channel');
        });

        $s->create('lg_campaigns', function (Blueprint $t) {
            $t->bigInteger('id', true);
            $t->integer('project_id');
            $t->string('name', 160);
            $t->unsignedBigInteger('profile_id')->nullable();
            $t->unsignedBigInteger('list_id')->nullable();
            $t->string('channel', 16);
            // email_account_id / channel_connection_id the messages go out through
            $t->string('sender_type', 32)->nullable();
            $t->unsignedBigInteger('sender_id')->nullable();
            $t->json('steps');                                // [{delay_hours, template_id, stop_on_reply}]
            $t->json('schedule')->nullable();                 // window, weekdays, timezone mode
            $t->unsignedInteger('daily_cap')->default(50);
            $t->string('lawful_basis', 40)->nullable();       // consent | legitimate_interest | existing_customer
            $t->text('lawful_basis_note')->nullable();
            // draft | pending_approval | approved | running | paused | stopped | completed
            $t->string('status', 20)->default('draft');
            $t->integer('approved_by')->nullable();
            $t->integer('approved_at')->nullable();
            $t->integer('launched_at')->nullable();
            $t->integer('stopped_at')->nullable();
            $t->json('stats')->nullable();                    // cached counters for list pages
            $t->integer('created_by')->nullable();
            $t->integer('created_at')->nullable();
            $t->integer('updated_at')->nullable();
            $t->integer('deleted_at')->nullable();
            $t->index(['project_id', 'status'], 'lg_camp_status');
        });

        $s->create('lg_campaign_recipients', function (Blueprint $t) {
            $t->bigInteger('id', true);
            $t->integer('project_id');
            $t->unsignedBigInteger('campaign_id');
            $t->unsignedBigInteger('prospect_id');
            $t->unsignedBigInteger('contact_id')->nullable();
            // pending | excluded | active | replied | opted_out | bounced | completed | stopped
            $t->string('state', 16)->default('pending');
            $t->string('excluded_reason', 64)->nullable();
            $t->string('address', 191)->nullable();           // the email / phone / PSID it goes to, frozen at launch
            $t->unsignedSmallInteger('current_step')->default(0);
            $t->integer('next_action_at')->nullable();
            $t->integer('last_sent_at')->nullable();
            $t->integer('replied_at')->nullable();
            $t->integer('created_at')->nullable();
            $t->integer('updated_at')->nullable();
            $t->unique(['campaign_id', 'prospect_id'], 'lg_cr_unique');
            $t->index(['project_id', 'state', 'next_action_at'], 'lg_cr_due');
            $t->index(['project_id', 'address'], 'lg_cr_address');
        });

        $s->create('lg_outreach_messages', function (Blueprint $t) {
            $t->bigInteger('id', true);
            $t->integer('project_id');
            $t->unsignedBigInteger('campaign_id')->nullable();   // null = one-off send from a prospect
            $t->unsignedBigInteger('recipient_id')->nullable();
            $t->unsignedBigInteger('prospect_id');
            $t->unsignedSmallInteger('step')->default(0);
            $t->string('channel', 16);
            $t->string('language', 12)->nullable();
            $t->string('subject', 255)->nullable();
            $t->text('body');
            $t->boolean('ai_generated')->default(false);
            // draft | approved | queued | sending | accepted | delivered | read | replied
            // | bounced | failed | unknown (provider may or may not have it — never auto-retried)
            $t->string('status', 12)->default('draft');
            $t->string('idempotency_key', 120)->nullable();
            $t->string('provider_message_id', 255)->nullable();
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->text('last_error')->nullable();
            $t->integer('approved_by')->nullable();
            $t->integer('scheduled_for')->nullable();
            $t->integer('queued_at')->nullable();
            $t->integer('sent_at')->nullable();
            $t->integer('delivered_at')->nullable();
            $t->integer('read_at')->nullable();
            $t->integer('replied_at')->nullable();
            $t->integer('created_by')->nullable();
            $t->integer('created_at')->nullable();
            $t->integer('updated_at')->nullable();
            $t->unique('idempotency_key', 'lg_om_idem');
            $t->unique(['channel', 'provider_message_id'], 'lg_om_provider_id');
            $t->index(['project_id', 'status'], 'lg_om_status');
            $t->index(['project_id', 'campaign_id', 'status'], 'lg_om_campaign');
            $t->index('prospect_id', 'lg_om_prospect');
        });

        // Never contact again. Checked when a campaign is built AND again at
        // send time, so an opt-out that lands mid-campaign still wins.
        $s->create('lg_suppressions', function (Blueprint $t) {
            $t->bigInteger('id', true);
            $t->integer('project_id');
            $t->string('kind', 8);                            // email | phone | domain
            $t->string('value_norm', 191);
            $t->string('reason', 16);                         // opt_out | bounce | complaint | manual | deleted
            $t->string('source', 64)->nullable();             // unsubscribe_link | keyword | dsn | user:<id> …
            $t->unsignedBigInteger('contact_id')->nullable();
            $t->integer('created_by')->nullable();
            $t->integer('created_at')->nullable();
            $t->unique(['project_id', 'kind', 'value_norm'], 'lg_sup_unique');
        });

        // Append-only execution log for searches, imports, campaigns and sends.
        $s->create('lg_events', function (Blueprint $t) {
            $t->bigInteger('id', true);
            $t->integer('project_id');
            $t->string('subject_type', 24);                   // search | import | campaign | message | prospect
            $t->unsignedBigInteger('subject_id');
            $t->string('event', 64);
            $t->string('level', 8)->default('info');          // info | warning | error
            $t->json('payload')->nullable();
            $t->integer('actor_id')->nullable();
            $t->integer('created_at');
            $t->index(['project_id', 'subject_type', 'subject_id', 'created_at'], 'lg_ev_subject');
            $t->index(['project_id', 'level', 'created_at'], 'lg_ev_level');
        });
    }

    public function down(): void
    {
        $s = Schema::connection('tenant');
        foreach ([
            'lg_events', 'lg_suppressions', 'lg_outreach_messages', 'lg_campaign_recipients',
            'lg_campaigns', 'lg_templates', 'lg_qualifications', 'lg_list_items', 'lg_lists',
            'lg_search_results', 'lg_prospects', 'lg_searches', 'lg_offer_profiles',
        ] as $table) {
            $s->dropIfExists($table);
        }
    }
};
