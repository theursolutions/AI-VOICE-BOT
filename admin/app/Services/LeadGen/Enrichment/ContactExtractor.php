<?php

namespace App\Services\LeadGen\Enrichment;

use App\Services\LeadGen\ProspectNormalizer;
use DOMDocument;
use DOMXPath;

/**
 * Pulls business contact details out of one HTML page. Pure — no fetching.
 *
 * Deliberately narrow:
 *   emails   mailto: links plus addresses written in the visible text,
 *            minus obvious non-addresses (image names like logo@2x.png,
 *            tracking / platform addresses, placeholders)
 *   phones   tel: links only — free-text digit runs are too often prices,
 *            licence numbers or dates to trust
 *   socials  links to known social platforms
 *   pages    same-site links that look like contact / about pages
 */
class ContactExtractor
{
    private const JUNK_EMAIL = '/(\.(png|jpe?g|gif|svg|webp|css|js)$)|(@(example|domain|email|sentry|wixpress|sentry-next)\.)|(^(no-?reply|donotreply|user|name|email|your)@)/i';

    private const CONTACT_PAGE = '/(contact|get-in-touch|reach-us|about|team|enquir|inquir|support|location|تواصل|اتصل)/iu';

    public function extract(string $html, string $pageUrl): array
    {
        $out = ['emails' => [], 'phones' => [], 'socials' => [], 'pages' => []];
        if (trim($html) === '') {
            return $out;
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . mb_substr($html, 0, 2_000_000));
        libxml_clear_errors();
        $xp = new DOMXPath($dom);

        // Scripts and styles are not content.
        foreach ($xp->query('//script|//style|//noscript') as $n) {
            $n->parentNode?->removeChild($n);
        }

        $host = strtolower((string) parse_url($pageUrl, PHP_URL_HOST));
        $hostBare = preg_replace('/^www\./', '', $host);

        foreach ($xp->query('//a[@href]') as $a) {
            $href = trim((string) $a->getAttribute('href'));
            if (stripos($href, 'mailto:') === 0) {
                $addr = rawurldecode(explode('?', substr($href, 7))[0]);
                $this->addEmail($out, $addr);
            } elseif (stripos($href, 'tel:') === 0) {
                $out['phones'][] = trim(rawurldecode(substr($href, 4)));
            } elseif (preg_match('#^(https?:)?//#i', $href) || str_starts_with($href, '/')) {
                $abs = \App\Support\SafeFetcher::absolutise($pageUrl, $href);
                if ($platform = ProspectNormalizer::socialPlatform($abs)) {
                    $out['socials'][$abs] = $platform;
                    continue;
                }
                $linkHost = preg_replace('/^www\./', '', strtolower((string) parse_url($abs, PHP_URL_HOST)));
                $label = $href . ' ' . $a->textContent;
                if ($linkHost === $hostBare && preg_match(self::CONTACT_PAGE, $label)) {
                    $out['pages'][] = strtok($abs, '#');
                }
            }
        }

        // Text node by node, space-separated: textContent glues adjacent
        // elements together, so "<span>+966…442</span><span>info@…</span>"
        // would read as one bogus address.
        $parts = [];
        foreach ($xp->query('//body//text()') as $node) {
            $parts[] = $node->nodeValue;
        }
        $text = implode(' ', $parts);
        if (preg_match_all('/(?<![A-Z0-9._%+\-])[A-Z][A-Z0-9._%\-]*@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $m)) {
            foreach ($m[0] as $addr) {
                $this->addEmail($out, $addr);
            }
        }

        $out['emails']  = array_values(array_unique($out['emails']));
        $out['phones']  = array_values(array_unique(array_filter($out['phones'])));
        $out['pages']   = array_values(array_unique($out['pages']));
        $out['socials'] = array_map(fn ($url, $p) => ['platform' => $p, 'url' => ProspectNormalizer::website($url)], array_keys($out['socials']), $out['socials']);
        return $out;
    }

    private function addEmail(array &$out, string $raw): void
    {
        $e = ProspectNormalizer::email(trim($raw, " \t\n\r\0\x0B.,;:()<>[]\"'"));
        if ($e && !preg_match(self::JUNK_EMAIL, $e)) {
            $out['emails'][] = $e;
        }
    }

    /** Generic business inboxes we may use; anything else looks like a person. */
    public static function isRoleAddress(string $email): bool
    {
        $local = strtolower(strtok($email, '@'));
        return (bool) preg_match('/^(info|contact|contactus|hello|hi|sales|enquir(y|ies)|inquir(y|ies)|office|admin|support|help|team|business|marketing|leasing|bookings?|reservations?|customer(service|care)?|care|general|mail)([._\-]?[a-z]*)?$/', $local);
    }
}
