<?php

namespace App\Services\LeadGen;

use App\Models\Contact;
use App\Models\LeadGen\LeadGenModel;
use App\Models\LeadGen\Prospect;
use App\Models\LeadGen\Search;
use App\Models\LeadGen\SearchResult;
use App\Services\Crm\ContactResolver;
use Illuminate\Support\Facades\DB;

/**
 * Saves one page of normalised prospects for a search or import.
 *
 * For each record, in order:
 *
 *   1. same source record seen before        → reuse it        ("duplicate")
 *   2. on the do-not-contact list as deleted → skip it         ("excluded")
 *   3. same business already stored (strong
 *      Deduplicator match)                   → fold into it    ("duplicate")
 *   4. same business earlier in this page    → fold into that  ("duplicate")
 *   5. otherwise                             → new prospect    ("new")
 *
 * Folding in fills only the existing prospect's empty fields, with the
 * provenance of where each came from, and records the other source — it
 * never overwrites a value someone may have checked.
 *
 * With `exclude_existing_crm`, a record whose email or phone is already on a
 * CRM contact is reported as "existing_crm" and linked to that contact.
 *
 * Idempotent, because the job calling it can be retried mid-page: inserts are
 * insertOrIgnore'd against unique keys, and counts only include links this
 * search didn't already have.
 */
class ProspectWriter
{
    /** Columns written on insert; the rest keep their defaults. */
    private const COLUMNS = [
        'business_name', 'category', 'website', 'domain_norm', 'phone_e164', 'email', 'address',
        'city', 'country_code', 'lat', 'lng', 'socials', 'source_provider', 'source_record_id',
        'source_url', 'source_license', 'source_meta', 'field_meta',
    ];

    private const JSON = ['address', 'socials', 'source_meta', 'field_meta'];

    /** Fields folding-in may fill on an existing prospect. */
    private const FILLABLE = ['website' => 'website', 'domain_norm' => null, 'phone_e164' => 'phone', 'email' => 'email'];

    public function __construct(private SuppressionService $suppressions) {}

    /**
     * @param array<int, array> $items ProspectNormalizer::make() arrays, one provider
     * @return array{new:int, duplicate:int, existing_crm:int, excluded:int, skipped:int}
     */
    public function write(Search $search, array $items, ?int $actorId = null): array
    {
        // A record with no name or no source id can't be shown or deduplicated.
        $given   = count($items);
        $items   = array_values(array_filter($items, fn ($i) => trim((string) ($i['business_name'] ?? '')) !== '' && ($i['source_record_id'] ?? '') !== ''));
        $zero    = ['new' => 0, 'duplicate' => 0, 'existing_crm' => 0, 'excluded' => 0, 'skipped' => $given - count($items)];
        if (!$items) {
            return $zero;
        }

        $projectId = LeadGenModel::currentProjectId();
        $provider  = $items[0]['source_provider'];
        $ids       = array_column($items, 'source_record_id');
        $now       = time();

        return DB::connection('tenant')->transaction(function () use ($search, $items, $projectId, $provider, $ids, $now, $actorId, $zero) {
            $known      = Prospect::where('source_provider', $provider)->whereIn('source_record_id', $ids)->pluck('id', 'source_record_id')->all();
            $suppressed = $this->suppressions->matchItems($items);
            $candidates = $this->candidates($items);

            $insert   = [];   // source_record_id => row
            $accepted = [];   // in-page canonical items, for within-page dedup
            $target   = [];   // source_record_id => ['prospect_id' => int] or ['alias' => source_record_id]
            $folds    = [];   // prospect_id => [items]
            $excluded = 0;

            foreach ($items as $item) {
                $sid = $item['source_record_id'];
                if (isset($known[$sid])) {
                    $target[$sid] = ['prospect_id' => $known[$sid], 'outcome' => SearchResult::DUPLICATE];
                    continue;
                }
                if (($suppressed[$sid] ?? null) === 'deleted') {
                    $excluded++;
                    continue;
                }
                [$kind, $match] = Deduplicator::bestMatch($item, $candidates);
                if ($kind === Deduplicator::STRONG) {
                    $target[$sid] = ['prospect_id' => (int) $match['id'], 'outcome' => SearchResult::DUPLICATE];
                    $folds[(int) $match['id']][] = $item;
                    continue;
                }
                [$pageKind, $pageMatch] = Deduplicator::bestMatch($item, $accepted);
                if ($pageKind === Deduplicator::STRONG) {
                    $target[$sid] = ['alias' => $pageMatch['source_record_id'], 'outcome' => SearchResult::DUPLICATE];
                    continue;
                }

                $row = ['project_id' => $projectId, 'status' => 'new', 'discovered_at' => $now,
                        'created_by' => $actorId, 'created_at' => $now, 'updated_at' => $now,
                        'suppressed_at' => isset($suppressed[$sid]) ? $now : null,
                        'duplicate_of' => $kind === Deduplicator::POSSIBLE ? (int) $match['id'] : null];
                foreach (self::COLUMNS as $col) {
                    $v = $item[$col] ?? null;
                    $row[$col] = in_array($col, self::JSON, true) && $v !== null ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v;
                }
                $insert[$sid]   = $row;
                $accepted[]     = $item + ['id' => count($accepted)];
                $target[$sid]   = ['new' => true, 'outcome' => SearchResult::NEW];
            }

            foreach (array_chunk(array_values($insert), 200) as $chunk) {
                Prospect::query()->insertOrIgnore($chunk);
            }
            $stored = $insert
                ? Prospect::where('source_provider', $provider)->whereIn('source_record_id', array_keys($insert))->pluck('id', 'source_record_id')->all()
                : [];

            foreach ($folds as $prospectId => $foldItems) {
                $this->foldInto($prospectId, $foldItems, $now);
            }

            $inCrm = ($search->criteria['exclude_existing_crm'] ?? false) ? $this->crmMatches($items) : [];

            $links = [];
            foreach ($items as $item) {
                $sid = $item['source_record_id'];
                $t = $target[$sid] ?? null;
                if (!$t) {
                    continue;
                }
                $pid = $t['prospect_id'] ?? (isset($t['alias']) ? ($stored[$t['alias']] ?? null) : ($stored[$sid] ?? null));
                if (!$pid) {
                    continue;
                }
                $contactId = $inCrm[$sid] ?? null;
                $outcome = $contactId !== null ? SearchResult::EXISTING_CRM : $t['outcome'];
                // One link per prospect per search; the first outcome stands.
                $links[$pid] ??= ['project_id' => $projectId, 'search_id' => $search->id, 'prospect_id' => $pid,
                                  'outcome' => $outcome, 'created_at' => $now];
                if ($contactId !== null) {
                    // Remember the link; "Add to CRM" will reuse this contact.
                    Prospect::whereKey($pid)->whereNull('contact_id')->update(['contact_id' => $contactId]);
                }
            }

            // Count from what this search hasn't linked yet, so a replayed
            // page reports zero new rows instead of double-counting.
            $counts = $zero;
            $counts['excluded'] = $excluded;
            $already = SearchResult::where('search_id', $search->id)->whereIn('prospect_id', array_keys($links))
                ->pluck('prospect_id')->all();
            foreach (array_diff_key($links, array_flip($already)) as $link) {
                $counts[$link['outcome']]++;
            }
            SearchResult::query()->insertOrIgnore(array_values($links));

            return $counts;
        });
    }

    /** Stored prospects sharing a website host, email or phone with any item. */
    private function candidates(array $items): array
    {
        $domains = array_values(array_unique(array_filter(array_column($items, 'domain_norm'))));
        $emails  = array_values(array_unique(array_filter(array_column($items, 'email'))));
        $phones  = array_values(array_unique(array_filter(array_column($items, 'phone_e164'))));
        if (!$domains && !$emails && !$phones) {
            return [];
        }
        return Prospect::where(function ($q) use ($domains, $emails, $phones) {
                if ($domains) { $q->orWhereIn('domain_norm', $domains); }
                if ($emails)  { $q->orWhereIn('email', $emails); }
                if ($phones)  { $q->orWhereIn('phone_e164', $phones); }
            })
            ->orderBy('id')
            ->get(['id', 'business_name', 'domain_norm', 'email', 'phone_e164'])
            ->map(fn ($p) => $p->only(['id', 'business_name', 'domain_norm', 'email', 'phone_e164']))
            ->all();
    }

    /** Fill an existing prospect's empty contact fields from other sources' records. */
    private function foldInto(int $prospectId, array $items, int $now): void
    {
        $p = Prospect::find($prospectId);
        if (!$p) {
            return;
        }
        $meta    = (array) $p->field_meta;
        $sources = (array) ($p->source_meta['also_from'] ?? []);
        $socials = (array) $p->socials;

        foreach ($items as $item) {
            foreach (self::FILLABLE as $col => $metaKey) {
                if (blank($p->{$col}) && !blank($item[$col] ?? null)) {
                    $p->{$col} = $item[$col];
                    if ($metaKey && isset($item['field_meta'][$metaKey])) {
                        $meta[$metaKey] = $item['field_meta'][$metaKey];
                    }
                }
            }
            $have = array_column($socials, 'url');
            foreach ((array) ($item['socials'] ?? []) as $s) {
                if (!in_array($s['url'], $have, true)) {
                    $socials[] = $s;
                }
            }
            $ref = ['provider' => $item['source_provider'], 'record_id' => $item['source_record_id'], 'license' => $item['source_license'] ?? null];
            if (!in_array($ref, $sources, true)) {
                $sources[] = $ref;
            }
        }

        $p->field_meta  = $meta ?: null;
        $p->socials     = $socials ?: null;
        $p->source_meta = ['also_from' => array_slice($sources, -20)] + (array) $p->source_meta;
        $p->save();
    }

    /**
     * source_record_id => contact id, for items whose email or phone is
     * already on a CRM contact. Phones compare as digits, which is how
     * ContactResolver stores them.
     */
    private function crmMatches(array $items): array
    {
        if (!ContactResolver::available()) {
            return [];
        }
        $emails = array_values(array_filter(array_column($items, 'email')));
        $phones = array_values(array_filter(array_map(
            fn ($i) => $i['phone_e164'] ? ltrim($i['phone_e164'], '+') : null, $items)));
        if (!$emails && !$phones) {
            return [];
        }

        $byEmail = $byPhone = [];
        Contact::where('project_id', LeadGenModel::currentProjectId())->where(function ($q) use ($emails, $phones) {
            $q->whereIn('email', $emails ?: ['']);
            $q->orWhereIn('phone', $phones ?: ['']);
        })->get(['id', 'email', 'phone'])->each(function ($c) use (&$byEmail, &$byPhone) {
            if ($c->email) { $byEmail[strtolower($c->email)] ??= $c->id; }
            if ($c->phone) { $byPhone[preg_replace('/\D+/', '', $c->phone)] ??= $c->id; }
        });

        $out = [];
        foreach ($items as $i) {
            $id = ($i['email'] ? ($byEmail[$i['email']] ?? null) : null)
               ?? ($i['phone_e164'] ? ($byPhone[ltrim($i['phone_e164'], '+')] ?? null) : null);
            if ($id) {
                $out[$i['source_record_id']] = (int) $id;
            }
        }
        return $out;
    }
}
