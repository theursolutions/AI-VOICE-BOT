<?php

namespace Tests\Feature\Billing;

use App\Models\Billing\ExchangeRate;
use App\Models\Billing\Plan;

/**
 * The plans section on the HOMEPAGE.
 *
 * This is the primary place customers see pricing, so it gets the same
 * treatment as checkout: every number must come from the database, and the
 * section must degrade to nothing rather than 500 the homepage.
 */
class HomepagePlansTest extends BillingTestCase
{
    public function test_the_homepage_renders_every_plan_with_its_seeded_price(): void
    {
        $response = $this->get('/');

        $response->assertOk();

        // Names and prices, straight from the seeded tables.
        foreach (['Free', 'Starter', 'Growth', 'Scale', 'Enterprise'] as $name) {
            $response->assertSee($name, false);
        }

        $response->assertSee('$19', false);
        $response->assertSee('$59', false);
        $response->assertSee('$149', false);
        $response->assertSee('Most popular', false);
        $response->assertSee('All plans are charged in USD', false);
        $response->assertSee('id="pricing"', false);
    }

    public function test_the_homepage_lists_what_each_plan_includes(): void
    {
        $response = $this->get('/');

        // Volume, verbatim from plan_features — "what we are giving them".
        $response->assertSee('5,000 AI conversations per month', false);   // Growth
        $response->assertSee('300 Phone call minutes per month', false);   // Growth
        $response->assertSee('1,200 Phone call minutes per month', false); // Scale
        $response->assertSee('50 Widget voice messages per month', false); // Free

        // A limit of 1 must read singular, not "1 Projects".
        $response->assertSee('1 Project', false);
        $response->assertSee('1 AI agent', false);

        // -1 renders as "Unlimited", and a brand name survives it intact.
        $response->assertSee('Unlimited Data sources', false);
        $response->assertSee('Unlimited WhatsApp, Instagram &amp; Facebook', false);

        // Bucket-1 features that are on every plan.
        $response->assertSee('Replies in 13 languages', false);
        $response->assertSee('Automatic lead capture', false);

        // Gated features.
        $response->assertSee('API access', false);

        // Free text renders as "Name: value".
        $response->assertSee('Support: Priority email', false);

        // Group headings from features.group.
        $response->assertSee('Volume', false);
        $response->assertSee('Channels &amp; power features', false);

        // The full matrix is on the page too, collapsed.
        $response->assertSee('Compare every feature across all plans', false);
    }

    public function test_the_homepage_prices_are_driven_by_the_database_not_the_markup(): void
    {
        // The real proof that it's dynamic: change the price, reload, see it.
        $growth = Plan::where('slug', 'growth')->firstOrFail();
        $growth->priceFor('monthly')->forceFill(['unit_amount' => 7700])->save();

        $this->get('/')->assertSee('$77', false);

        // And renaming the plan changes the card.
        $growth->forceFill(['name' => 'Momentum', 'badge' => 'Best value'])->save();

        $this->get('/')
             ->assertSee('Momentum', false)
             ->assertSee('Best value', false);
    }

    public function test_hiding_a_plan_removes_it_from_the_homepage(): void
    {
        Plan::where('slug', 'scale')->update(['is_active' => false]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertDontSee('$149', false);
        $response->assertSee('$59', false);
    }

    public function test_a_private_plan_is_not_shown_on_the_homepage(): void
    {
        Plan::where('slug', 'starter')->update(['is_public' => false]);

        $this->get('/')->assertOk()->assertDontSee('$19', false);
    }

    public function test_the_annual_interval_renders_and_shows_the_saving(): void
    {
        $this->get('/?billing=annually')
             ->assertOk()
             ->assertSee('$590', false)
             ->assertSee('Save 17%', false)
             ->assertSee('$49.17/mo billed annually', false);
    }

    public function test_the_homepage_shows_approximate_local_prices_when_available(): void
    {
        ExchangeRate::updateOrCreate(
            ['base' => 'USD', 'currency' => 'PKR'],
            ['rate' => 283.4123, 'provider' => 'test', 'fetched_at' => now()]
        );

        $this->get('/?country=PK')
             ->assertOk()
             ->assertSee('$19', false)          // charged
             ->assertSee('Rs 5,400', false)     // approximate
             ->assertSee('approximate', false);
    }

    public function test_the_homepage_checkout_form_submits_only_a_slug_and_interval(): void
    {
        $html = $this->get('/')->getContent();

        $this->assertStringContainsString('name="plan" value="growth"', $html);
        $this->assertStringContainsString('name="interval"', $html);

        // No money-shaped field may reach the browser's form.
        $this->assertStringNotContainsString('name="amount"', $html);
        $this->assertStringNotContainsString('name="unit_amount"', $html);
        $this->assertStringNotContainsString('name="price"', $html);
        $this->assertStringNotContainsString('name="stripe_price_ref"', $html);
    }

    public function test_the_homepage_still_renders_with_no_plans_configured(): void
    {
        // A fresh install before BillingSeeder runs. The section must vanish,
        // not take the homepage down with it.
        \App\Models\Billing\PlanFeature::query()->delete();
        \App\Models\Billing\PlanPrice::query()->delete();
        Plan::query()->forceDelete();

        $response = $this->get('/');

        $response->assertOk();
        $response->assertDontSee('id="pricing"', false);
        $response->assertSee('never sleeps', false);   // the rest of the page is intact
    }

    public function test_the_standalone_pricing_page_shows_the_same_numbers(): void
    {
        // Both surfaces render the same partial from the same view model, so
        // they can't drift apart.
        $home    = $this->get('/')->getContent();
        $pricing = $this->get('/pricing')->getContent();

        foreach (['$19', '$59', '$149', 'Most popular'] as $needle) {
            $this->assertStringContainsString($needle, $home, "homepage: {$needle}");
            $this->assertStringContainsString($needle, $pricing, "/pricing: {$needle}");
        }
    }
}
