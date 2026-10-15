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
 * Saves one page of normalised prospects for a search.
 *
 * Idempotent, because the job calling it can be retried mid-page: prospects
 * are insertOrIgnore'd against the (project, provider, source record) unique
 * key and search results against (search, prospect). Running the same page
 * twice changes nothing and reports the same counts.
 *
 * A record already found by an earlier search is reused, not duplicated —
 * its outcome here is "duplicate". With `exclude_existing_crm`, a record
 * whose email or phone already belongs to a CRM contact is marked
 * "existing_crm" (same strong identifiers ContactResolver merges on; never
 * the name). Matching the same business across sources is the Deduplicator's.
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

    /**
     * @param array<int, array> $items ProspectNormalizer::make() arrays, one provider
     * @return array{new:int, duplicate:int, existing_crm:int, skipped:int}
     */
    public function write(Search $search, array $items, ?int $actorId = null): array
    {
        // A record with no name or no source id can't be shown or deduplicated.
        $given   = count($items);
        $items   = array_values(array_filter($items, fn ($i) => trim((string) ($i['business_name'] ?? '')) !== '' && ($i['source_record_id'] ?? '') !== ''));
        $skipped = $given - count($items);
        if (!$items) {
            return ['new' => 0, 'duplicate' => 0, 'existing_crm' => 0, 'skipped' => $skipped];
        }

        $projectId = LeadGenModel::currentProjectId();
        $provider  = $items[0]['source_provider'];
        $ids       = array_column($items, 'source_record_id');
        $now       = time();

        return DB::connection('tenant')->transaction(function () use ($search, $items, $projectId, $provider, $ids, $now, $actorId, $skipped) {
            $before = Prospect::where('source_provider', $provider)->whereIn('source_record_id', $ids)
                ->pluck('id', 'source_record_id')->all();

            $rows = [];
            foreach ($items as $item) {
                if (isset($before[$item['source_record_id']])) {
                    continue;
                }
                $row = ['project_id' => $projectId, 'status' => 'new', 'discovered_at' => $now,
                        'created_by' => $actorId, 'created_at' => $now, 'updated_at' => $now];
                foreach (self::COLUMNS as $col) {
                    $v = $item[$col] ?? null;
                    $row[$col] = in_array($col, self::JSON, true) && $v !== null ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v;
                }
                $rows[] = $row;
            }
            foreach (array_chunk($rows, 200) as $chunk) {
                Prospect::query()->insertOrIgnore($chunk);
            }

            $all = Prospect::where('source_provider', $provider)->whereIn('source_record_id', $ids)
                ->pluck('id', 'source_record_id')->all();

            $inCrm = ($search->criteria['exclude_existing_crm'] ?? false) ? $this->crmMatches($items) : [];

            $links = [];
            $counts = ['new' => 0, 'duplicate' => 0, 'existing_crm' => 0];
            foreach ($items as $item) {
                $pid = $all[$item['source_record_id']] ?? null;
                if (!$pid) {
                    continue;
                }
                $contactId = $inCrm[$item['source_record_id']] ?? null;
                $outcome = match (true) {
                    $contactId !== null                       => SearchResult::EXISTING_CRM,
                    isset($before[$item['source_record_id']]) => SearchResult::DUPLICATE,
                    default                                   => SearchResult::NEW,
                };
                $links[$pid] = ['project_id' => $projectId, 'search_id' => $search->id, 'prospect_id' => $pid,
                                'outcome' => $outcome, 'created_at' => $now];
                if ($contactId !== null) {
                    // Remember the link; "Add to CRM" will reuse this contact.
                    Prospect::whereKey($pid)->whereNull('contact_id')->update(['contact_id' => $contactId]);
                }
            }
            // Count from what this search hasn't linked yet, so a replayed
            // page reports zero new rows instead of double-counting.
            $already = SearchResult::where('search_id', $search->id)->whereIn('prospect_id', array_keys($links))
                ->pluck('prospect_id')->all();
            foreach (array_diff_key($links, array_flip($already)) as $link) {
                $counts[$link['outcome']]++;
            }
            SearchResult::query()->insertOrIgnore(array_values($links));

            return ['new' => $counts['new'], 'duplicate' => $counts['duplicate'],
                    'existing_crm' => $counts['existing_crm'], 'skipped' => $skipped];
        });
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
