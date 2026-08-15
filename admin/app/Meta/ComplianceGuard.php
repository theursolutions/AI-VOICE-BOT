<?php

namespace App\Meta;

use App\Models\Session;

/**
 * The rules that keep a customer's number out of trouble.
 *
 * Meta does not block numbers for using a bot — automation inside the
 * 24-hour service window is explicitly allowed. Numbers get restricted when
 * RECIPIENTS complain: block rate above roughly 2% drops the quality rating,
 * and a sustained red rating leads to messaging limits and then suspension.
 *
 * So the only real lever is not annoying people. Three behaviours cause
 * almost all of it, and all three are ours to prevent:
 *
 *   1. Continuing to message someone who asked you to stop
 *   2. Trapping someone in a bot loop with no way to reach a human
 *   3. Flooding — several messages where one would do
 *
 * This class is deliberately channel-agnostic and applied in
 * CrmInboundMessageHandler, which every Meta channel funnels through, so
 * WhatsApp, Messenger and Instagram all get the same protection.
 */
class ComplianceGuard
{
    /**
     * Opt-out phrases.
     *
     * English, Urdu and roman Urdu, because a Pakistani customer typing
     * "band karo" and being ignored is exactly the person who then presses
     * Block — and a rule that only understands English would miss them.
     *
     * Matched as whole words against the trimmed message so an order for
     * "stop lights" is not read as an opt-out.
     */
    private const OPT_OUT = [
        'stop', 'unsubscribe', 'cancel', 'quit', 'end', 'opt out', 'optout',
        'remove me', 'do not message', "don't message", 'no more messages',
        'leave me alone', 'block',
        // Urdu / roman Urdu
        'band karo', 'band karen', 'bas karo', 'mat bhejo', 'message mat',
        'روکو', 'بند کرو', 'بند کریں',
    ];

    /** Coming back. Meta requires opt-out to be reversible by the user. */
    private const OPT_IN = [
        'start', 'resume', 'subscribe', 'unstop', 'shuru karo', 'شروع',
    ];

    /**
     * Asking for a person. Honouring these is a POLICY REQUIREMENT — Meta
     * expects a "prompt, clear and direct escalation path" — and it is also
     * the cheapest way to stop a frustrated customer reaching for Block.
     */
    private const WANTS_HUMAN = [
        'agent', 'human', 'representative', 'real person', 'operator',
        'talk to someone', 'speak to someone', 'customer service', 'support team',
        'insan', 'banda', 'namainda', 'کوئی بندہ', 'انسان',
    ];

    /**
     * How many consecutive bot replies before we hand over unprompted.
     *
     * A customer who has asked the same thing six times is not being helped;
     * they are being held. Escalating before they give up is the difference
     * between a handover and a block.
     */
    public const BOT_TURN_LIMIT = 6;

    public function isOptOut(string $text): bool
    {
        return $this->matches($text, self::OPT_OUT, strictSingleWords: true);
    }

    public function isOptIn(string $text): bool
    {
        return $this->matches($text, self::OPT_IN, strictSingleWords: true);
    }

    /**
     * Asking for a person is matched ANYWHERE in the sentence, unlike
     * opt-out. The two need opposite rules and it is worth being explicit
     * about why:
     *
     *   opt-out    the cost of a false positive is high (silence a live
     *              customer who typed "stop by tomorrow"), so a bare command
     *              must be the entire message
     *   wants-human  the cost of a false positive is trivial (a human gets
     *              a conversation they could have skipped) and the cost of a
     *              miss is a frustrated customer reaching for Block — so
     *              "mujhe kisi insan se baat karni hai" has to match
     */
    public function wantsHuman(string $text): bool
    {
        return $this->matches($text, self::WANTS_HUMAN, strictSingleWords: false);
    }

    /** Has this contact asked us to stop? */
    public function isOptedOut(Session $session): bool
    {
        return (bool) data_get($session->metadata, 'meta.opted_out');
    }

    /**
     * The one message we are allowed to send after an opt-out: a
     * confirmation. Silence would leave the customer unsure it worked, and
     * an unsure customer blocks the number to be certain.
     */
    public function optOutConfirmation(): string
    {
        return "You're unsubscribed — we won't message you again from this number. "
             . 'Reply START at any time if you change your mind.';
    }

    /**
     * Consecutive assistant replies since the customer last got a human.
     * Read from the session so it survives across queue workers.
     */
    public function botTurns(Session $session): int
    {
        return (int) data_get($session->metadata, 'meta.bot_turns', 0);
    }

    public function shouldEscalate(Session $session, string $text): bool
    {
        return $this->wantsHuman($text)
            || $this->botTurns($session) >= self::BOT_TURN_LIMIT;
    }

    /**
     * Case-insensitive match on word boundaries.
     *
     * `\p{L}` rather than `\b` so Urdu script works — `\b` is defined against
     * ASCII word characters and treats every Arabic-script letter as a
     * boundary, which would make "بند کریں" match inside anything.
     *
     * @param bool $strictSingleWords when true, a one-word phrase only counts
     *        if it is the ENTIRE message. Stops "bus stop" reading as an
     *        opt-out. Multi-word phrases always match anywhere.
     */
    private function matches(string $text, array $phrases, bool $strictSingleWords): bool
    {
        $normalised = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text)));

        foreach ($phrases as $phrase) {
            $needle = mb_strtolower($phrase);
            $single = ! str_contains($needle, ' ');

            if ($single && $strictSingleWords) {
                if ($normalised === $needle) {
                    return true;
                }
                continue;
            }

            if (preg_match('/(?<!\p{L})' . preg_quote($needle, '/') . '(?!\p{L})/u', $normalised)) {
                return true;
            }
        }

        return false;
    }
}
