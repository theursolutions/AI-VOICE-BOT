<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use Tests\TestCase;

/**
 * Theme resolution: the site default sets the starting point, the visitor's
 * own choice always overrides it.
 *
 * The precedence is the whole point. An admin flipping the site default must
 * never silently change the theme for someone who has deliberately picked
 * one — that reads as the product ignoring you.
 */
class ThemeDefaultTest extends TestCase
{
    public function test_a_fresh_visitor_gets_light_by_default(): void
    {
        $this->assertSame('light', tva_theme());
    }

    public function test_the_site_default_applies_when_the_visitor_has_not_chosen(): void
    {
        SiteSetting::set('content.default_theme', 'dark');

        $this->assertSame('dark', tva_theme());
        $this->assertSame('dark', tva_theme_class());
    }

    public function test_the_visitors_choice_beats_the_site_default(): void
    {
        SiteSetting::set('content.default_theme', 'dark');

        // The cookie is how a visitor's pick travels; the server reads it so
        // the class is in the initial HTML and there is no flash.
        $this->app['request']->cookies->set('tva_theme', 'light');

        $this->assertSame('light', tva_theme(), 'A deliberate choice must survive an admin changing the default.');
    }

    public function test_an_unrecognised_value_falls_back_to_light(): void
    {
        SiteSetting::set('content.default_theme', 'solarized');

        $this->assertSame('light', tva_theme());
    }

    /** The class is empty in light — light is the base, not a modifier. */
    public function test_light_emits_no_class(): void
    {
        $this->assertSame('', tva_theme_class());
    }
}
