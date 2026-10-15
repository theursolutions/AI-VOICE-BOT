<?php

namespace App\Services\LeadGen\Enrichment;

use App\Models\LeadGen\Event;
use App\Models\LeadGen\Prospect;
use App\Services\LeadGen\ProspectNormalizer;
use App\Support\SafeFetcher;

/**
 * Reads a prospect's OWN website for public business contact details and
 * records how well each contact field is verified.
 *
 * What it will and won't do:
 *   - fetches through SafeFetcher (private addresses refused, robots.txt
 *     honoured), the homepage plus at most MAX_EXTRA_PAGES contact/about
 *     pages on the same site
 *   - fills an EMPTY email only with a generic inbox on the business's own
 *     domain (info@, sales@ …). Other addresses it finds are listed as
 *     alternatives for a person to judge — a named employee's mailbox is
 *     personal data and is never auto-selected for outreach
 *   - fills an empty phone only from tel: links
 *   - checks the email domain has mail servers (MX); never probes a mailbox
 *   - never overwrites a value a person confirmed
 *
 * Verification ladder (Prospect::VERIFICATION): unverified → syntax_ok →
 * mx_ok → found_on_site → user_confirmed. A field only ever moves up.
 */
class WebsiteEnricher
{
    public const MAX_EXTRA_PAGES = 4;

    /** @var callable(string): bool */
    private $hasMx;

    public function __construct(private SafeFetcher $fetcher, private ContactExtractor $extractor, ?callable $hasMx = null)
    {
        $this->hasMx = $hasMx ?? fn (string $domain) => checkdnsrr($domain, 'MX');
    }

    public function enrich(Prospect $p): string
    {
        $now  = time();
        $meta = (array) $p->field_meta;

        if (!$p->website) {
            $this->mxCheck($p, $meta);
            return $this->finish($p, $meta, 'done', ['note' => 'no website'], $now);
        }

        $home = $this->fetcher->get($p->website);
        if ($home->blockedReason) {
            return $this->finish($p, $meta, 'blocked', ['reason' => $home->blockedReason], $now);
        }
        if (!$home->ok() || !$home->isHtml()) {
            $meta['website'] = ['verification' => 'unverified', 'checked_at' => $now, 'reachable' => false] + ($meta['website'] ?? []);
            $this->mxCheck($p, $meta);
            return $this->finish($p, $meta, 'failed', ['status' => $home->status, 'error' => $home->error], $now);
        }

        // The site answered: the website itself is confirmed.
        $meta['website'] = ['verification' => 'found_on_site', 'checked_at' => $now, 'reachable' => true, 'url' => $home->url]
            + ($meta['website'] ?? []);

        $found = $this->extractor->extract((string) $home->body, $home->url);
        $foundOn = array_fill_keys($found['emails'], $home->url) + array_fill_keys($found['phones'], $home->url);

        foreach (array_slice($found['pages'], 0, self::MAX_EXTRA_PAGES) as $url) {
            $page = $this->fetcher->get($url);
            if (!$page->ok() || !$page->isHtml()) {
                continue;
            }
            $more = $this->extractor->extract((string) $page->body, $page->url);
            foreach (['emails', 'phones'] as $k) {
                foreach ($more[$k] as $v) {
                    $foundOn[$v] ??= $page->url;
                }
                $found[$k] = array_values(array_unique(array_merge($found[$k], $more[$k])));
            }
            $found['socials'] = array_merge($found['socials'], $more['socials']);
        }

        $this->applyEmails($p, $meta, $found['emails'], $foundOn, $now);
        $this->applyPhones($p, $meta, $found['phones'], $foundOn, $now);
        $this->applySocials($p, $found['socials']);
        $this->mxCheck($p, $meta);

        return $this->finish($p, $meta, 'done', [
            'emails' => count($found['emails']), 'phones' => count($found['phones']), 'pages' => 1 + min(count($found['pages']), self::MAX_EXTRA_PAGES),
        ], $now);
    }

    private function applyEmails(Prospect $p, array &$meta, array $emails, array $foundOn, int $now): void
    {
        $siteDomain = $p->domain_norm ?: preg_replace('/^www\./', '', (string) parse_url((string) $p->website, PHP_URL_HOST));
        $onSite = fn (string $e) => $siteDomain && (str_ends_with($e, '@' . $siteDomain) || str_ends_with($e, '.' . $siteDomain));

        if ($p->email && in_array($p->email, $emails, true)) {
            $this->raise($meta, 'email', 'found_on_site', ['url' => $foundOn[$p->email] ?? null, 'checked_at' => $now]);
        }
        if (!$p->email) {
            $pick = collect($emails)->first(fn ($e) => $onSite($e) && ContactExtractor::isRoleAddress($e));
            if ($pick) {
                $p->email = $pick;
                $meta['email'] = ['source' => 'website', 'url' => $foundOn[$pick] ?? null, 'found_at' => $now, 'verification' => 'found_on_site'];
            }
        }
        $others = array_values(array_diff($emails, [$p->email]));
        if ($others) {
            $meta['email']['alternatives'] = array_slice($others, 0, 5);
        }
    }

    private function applyPhones(Prospect $p, array &$meta, array $phones, array $foundOn, int $now): void
    {
        $normalised = [];
        foreach ($phones as $raw) {
            if ($e164 = ProspectNormalizer::phone($raw, $p->country_code)) {
                $normalised[$e164] = $foundOn[$raw] ?? null;
            }
        }
        if ($p->phone_e164 && array_key_exists($p->phone_e164, $normalised)) {
            $this->raise($meta, 'phone', 'found_on_site', ['url' => $normalised[$p->phone_e164], 'checked_at' => $now]);
        } elseif (!$p->phone_e164 && $normalised) {
            $p->phone_e164 = array_key_first($normalised);
            $meta['phone'] = ['source' => 'website', 'url' => reset($normalised), 'found_at' => $now, 'verification' => 'found_on_site'];
        }
    }

    private function applySocials(Prospect $p, array $socials): void
    {
        $have = (array) $p->socials;
        $urls = array_column($have, 'url');
        foreach ($socials as $s) {
            if ($s['url'] && !in_array($s['url'], $urls, true)) {
                $have[] = $s;
                $urls[] = $s['url'];
            }
        }
        $p->socials = $have ? array_slice($have, 0, 10) : null;
    }

    /** Mail servers for the email's domain: raises to mx_ok, or records that there are none. */
    private function mxCheck(Prospect $p, array &$meta): void
    {
        if (!$p->email) {
            return;
        }
        $domain = substr(strrchr($p->email, '@'), 1);
        $has = (bool) ($this->hasMx)($domain);
        $meta['email']['mx'] = $has;
        if ($has) {
            $this->raise($meta, 'email', 'mx_ok', []);
        }
    }

    /** Move a field's verification up the ladder, never down. */
    private function raise(array &$meta, string $field, string $level, array $extra): void
    {
        $ladder  = Prospect::VERIFICATION;
        $current = $meta[$field]['verification'] ?? 'unverified';
        if (array_search($level, $ladder, true) > array_search($current, $ladder, true)) {
            $meta[$field]['verification'] = $level;
        }
        $meta[$field] = array_filter($extra, fn ($v) => $v !== null) + (array) ($meta[$field] ?? []);
    }

    private function finish(Prospect $p, array $meta, string $status, array $log, int $now): string
    {
        $p->field_meta        = $meta ?: null;
        $p->enrichment_status = $status;
        $p->last_verified_at  = $now;
        $p->save();
        Event::log('prospect', $p->id, 'enrichment.' . $status, $log, $status === 'done' ? 'info' : 'warning');
        return $status;
    }
}
