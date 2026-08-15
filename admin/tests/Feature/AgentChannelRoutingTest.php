<?php

namespace Tests\Feature;

use App\Models\BotAgent;
use Tests\TestCase;

/**
 * Channel assignment and capability gating.
 *
 * Both fail silently when wrong — a mis-scoped agent means conversations
 * quietly reach the wrong bot, and a mis-read capability either blocks a
 * permitted action or allows a forbidden one. Neither raises an error.
 */
class AgentChannelRoutingTest extends TestCase
{
    private function agent(array $metadata = [], string $type = BotAgent::TYPE_AI): BotAgent
    {
        $a = new BotAgent(['name' => 'Test', 'type' => $type]);
        $a->metadata = $metadata;

        return $a;
    }

    // ── Channels ─────────────────────────────────────────────────────

    /**
     * The upgrade path. Every agent that existed before this feature has no
     * `channels` key and must keep answering everything, or a deploy makes
     * every workspace go deaf at once.
     */
    public function test_an_agent_with_no_channels_configured_handles_all_of_them(): void
    {
        $agent = $this->agent();

        foreach (array_keys(BotAgent::CHANNELS) as $channel) {
            $this->assertTrue($agent->handlesChannel($channel), "should handle {$channel}");
        }
    }

    public function test_an_empty_channel_list_also_means_all(): void
    {
        $this->assertTrue($this->agent(['channels' => []])->handlesChannel('whatsapp'));
    }

    public function test_an_agent_only_handles_its_configured_channels(): void
    {
        $agent = $this->agent(['channels' => ['whatsapp']]);

        $this->assertTrue($agent->handlesChannel('whatsapp'));
        $this->assertFalse($agent->handlesChannel('instagram'));
        $this->assertFalse($agent->handlesChannel('facebook'));
    }

    public function test_an_agent_can_hold_several_channels(): void
    {
        $agent = $this->agent(['channels' => ['whatsapp', 'instagram']]);

        $this->assertTrue($agent->handlesChannel('whatsapp'));
        $this->assertTrue($agent->handlesChannel('instagram'));
        $this->assertFalse($agent->handlesChannel('facebook'));
    }

    /**
     * Sessions store `facebook`, the Meta provider says `facebook_page`, and
     * Messenger events say `messenger`. Whoever ticks "Facebook" in the UI
     * means all three, and should never have to know that.
     */
    public function test_the_facebook_spellings_are_treated_as_one_channel(): void
    {
        $agent = $this->agent(['channels' => ['facebook']]);

        $this->assertTrue($agent->handlesChannel('facebook'));
        $this->assertTrue($agent->handlesChannel('facebook_page'));
        $this->assertTrue($agent->handlesChannel('messenger'));
    }

    public function test_an_unknown_channel_is_ignored_rather_than_trusted(): void
    {
        $this->assertSame(['whatsapp'], $this->agent(['channels' => ['whatsapp', 'telegram']])->channels());
    }

    public function test_no_channel_at_all_matches_anything(): void
    {
        // Telephony and webchat call the router without a channel.
        $this->assertTrue($this->agent(['channels' => ['whatsapp']])->handlesChannel(null));
    }

    // ── Capabilities ─────────────────────────────────────────────────

    public function test_capabilities_default_to_allowed(): void
    {
        $agent = $this->agent();

        foreach (array_keys(BotAgent::CAPABILITIES) as $cap) {
            $this->assertTrue($agent->can($cap), "{$cap} should default to allowed");
        }
    }

    public function test_a_capability_can_be_withdrawn(): void
    {
        $agent = $this->agent(['capabilities' => ['transfer' => false, 'send_text' => true]]);

        $this->assertFalse($agent->can('transfer'));
        $this->assertTrue($agent->can('send_text'));
    }

    /**
     * A capability added in a later release must not retroactively forbid an
     * action for every existing agent. Failing closed on upgrade is a support
     * queue, not security.
     */
    public function test_an_unknown_capability_is_allowed(): void
    {
        $this->assertTrue($this->agent(['capabilities' => []])->can('some_future_action'));
    }

    public function test_the_capability_map_is_complete(): void
    {
        $caps = $this->agent(['capabilities' => ['transfer' => false]])->capabilities();

        $this->assertSame(array_keys(BotAgent::CAPABILITIES), array_keys($caps));
        $this->assertFalse($caps['transfer']);
        $this->assertTrue($caps['send_text'], 'Unset keys must fill in as allowed.');
    }

    /** Human-only permissions are meaningless on an AI persona. */
    public function test_capabilities_are_filtered_by_agent_type(): void
    {
        $forAi = BotAgent::capabilitiesFor(BotAgent::TYPE_AI);

        $this->assertArrayHasKey('send_text', $forAi);
        $this->assertArrayNotHasKey('transfer', $forAi);

        $this->assertArrayHasKey('transfer', BotAgent::capabilitiesFor(BotAgent::TYPE_HUMAN));
    }
}
