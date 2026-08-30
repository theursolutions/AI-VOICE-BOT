<?php

namespace Tests\Unit;

use App\Services\Billing\UsageRecorder;
use ReflectionClass;
use Tests\TestCase;

/**
 * The meter counts MESSAGES, not sessions.
 *
 * This is the change the whole pricing model rests on, so it is pinned at the
 * source level: the reply path must record `messages` unconditionally, and must
 * record `conversations` only behind the once-per-session guard.
 *
 * Asserted against the source rather than by driving a real turn because the
 * recorder needs a tenant database, a session, a client and a live LLM to reach
 * that line — a test that heavy would not be run, and the thing worth protecting
 * is a single structural property: that the `messages` call sits OUTSIDE the
 * `$assistantCount === 1` branch. Regressing it would silently restore
 * session-based metering, which reads as "costs went up" months later with
 * nothing pointing here.
 */
class MessageMeterTest extends TestCase
{
    private function source(): string
    {
        return file_get_contents((new ReflectionClass(UsageRecorder::class))->getFileName());
    }

    /** The body of assistantReplied(), where the metering decision lives. */
    private function replyBody(): string
    {
        $src   = $this->source();
        $start = strpos($src, 'public function assistantReplied');
        $end   = strpos($src, 'public function callCompleted');

        $this->assertNotFalse($start, 'assistantReplied() has been renamed');
        $this->assertNotFalse($end, 'callCompleted() has been renamed');

        return substr($src, $start, $end - $start);
    }

    public function test_the_reply_path_records_the_messages_metric(): void
    {
        $this->assertStringContainsString(
            "record(\$client, 'messages', 1",
            $this->replyBody(),
            'Every AI reply must be metered as one message — that is the unit the cost is incurred in',
        );
    }

    /**
     * The structural assertion. `messages` must be recorded before the
     * once-per-session count is even taken, so it cannot end up inside that
     * branch by a later edit.
     */
    public function test_messages_are_recorded_outside_the_once_per_session_guard(): void
    {
        $body = $this->replyBody();

        $messagesAt = strpos($body, "'messages'");
        $guardAt    = strpos($body, '$assistantCount = ');

        $this->assertNotFalse($messagesAt, "the 'messages' metric is no longer recorded");
        $this->assertNotFalse($guardAt, 'the once-per-session guard has been restructured');

        $this->assertLessThan(
            $guardAt,
            $messagesAt,
            'messages must be recorded BEFORE the session guard. Inside it, a plan sold as '
            . '1,000 conversations would again be unbounded: 20,000 messages at twenty turns '
            . 'and 100,000 at a hundred, on one price.',
        );
    }

    public function test_conversations_are_still_recorded_once_per_session(): void
    {
        $body = $this->replyBody();

        $this->assertStringContainsString('$assistantCount === 1', $body);

        $guardAt        = strpos($body, '$assistantCount === 1');
        $conversationAt = strpos($body, "'conversations'");

        $this->assertNotFalse($conversationAt, 'the conversations statistic has been dropped');
        $this->assertGreaterThan(
            $guardAt,
            $conversationAt,
            'conversations must stay inside the once-per-session guard, or the statistic '
            . 'that makes "1,000 conversations" checkable becomes a message count',
        );
    }

    /**
     * Both metrics must be declared, or reporting silently loses one: the
     * billing page enumerates config('billing.metrics') rather than the table.
     */
    public function test_both_metrics_are_declared_in_config(): void
    {
        $metrics = config('billing.metrics');

        $this->assertArrayHasKey('messages', $metrics);
        $this->assertArrayHasKey('conversations', $metrics);
        $this->assertSame('message', $metrics['messages']['unit']);
    }

    /**
     * Only one feature may claim a metric — allowanceFor() resolves the ceiling
     * with a single `where(metric_key)->value('key')`, so a second claimant
     * makes which cap applies depend on row order.
     */
    public function test_at_most_one_feature_claims_each_metric(): void
    {
        $dupes = \Illuminate\Support\Facades\DB::table('features')
            ->select('metric_key')
            ->whereNotNull('metric_key')
            ->groupBy('metric_key')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('metric_key')
            ->all();

        $this->assertSame([], $dupes, 'Two features claim the same metric_key: ' . implode(', ', $dupes));
    }
}
