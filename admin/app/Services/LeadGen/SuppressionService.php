<?php

namespace App\Services\LeadGen;

use App\Models\LeadGen\Prospect;
use App\Models\LeadGen\Suppression;

/**
 * The do-not-contact list, keyed on normalised email, phone (E.164) or
 * website host.
 *
 *   opt_out / bounce / complaint / manual  the business stays visible but is
 *                                          marked do-not-contact everywhere
 *   deleted                                a person removed it and chose
 *                                          "never re-add" — future searches
 *                                          and imports skip it entirely
 */
class SuppressionService
{
    public static function normalise(string $kind, ?string $value): ?string
    {
        return match ($kind) {
            'email'  => ProspectNormalizer::email($value),
            'phone'  => $value && str_starts_with(trim($value), '+') ? ProspectNormalizer::phone($value, null) : null,
            'domain' => ProspectNormalizer::domain($value),
            default  => null,
        };
    }

    /** Add (idempotent). Returns false when the value can't be normalised. */
    public function add(string $kind, ?string $value, string $reason, ?string $source = null, ?int $contactId = null): bool
    {
        $norm = self::normalise($kind, $value);
        if (!$norm || !in_array($reason, Suppression::REASONS, true)) {
            return false;
        }
        if (!Suppression::where('kind', $kind)->where('value_norm', $norm)->exists()) {
            try {
                Suppression::create([
                    'kind' => $kind, 'value_norm' => $norm, 'reason' => $reason, 'source' => $source,
                    'contact_id' => $contactId, 'created_by' => auth()->id(),
                ]);
            } catch (\Illuminate\Database\QueryException) {
                // Raced with another insert of the same value — it's on the list either way.
            }
        }
        // Everything already on file under that value is now do-not-contact.
        $column = ['email' => 'email', 'phone' => 'phone_e164', 'domain' => 'domain_norm'][$kind];
        Prospect::where($column, $norm)->whereNull('suppressed_at')->update(['suppressed_at' => time()]);
        return true;
    }

    /** Suppress every contact key a prospect has. */
    public function addProspect(Prospect $p, string $reason, string $source): void
    {
        foreach (['email' => $p->email, 'phone' => $p->phone_e164, 'domain' => $p->website] as $kind => $v) {
            if ($v) {
                $this->add($kind, $v, $reason, $source, $p->contact_id);
            }
        }
    }

    /**
     * Which of these normalised items hit the list, and why.
     *
     * @param  array<int, array> $items ProspectNormalizer arrays
     * @return array<string, string> source_record_id => reason ('deleted' wins)
     */
    public function matchItems(array $items): array
    {
        $keys = ['email' => [], 'phone' => [], 'domain' => []];
        foreach ($items as $i) {
            if (!empty($i['email']))       { $keys['email'][]  = $i['email']; }
            if (!empty($i['phone_e164']))  { $keys['phone'][]  = $i['phone_e164']; }
            if (!empty($i['domain_norm'])) { $keys['domain'][] = $i['domain_norm']; }
        }
        if (!array_filter($keys)) {
            return [];
        }

        $hits = [];
        Suppression::where(function ($q) use ($keys) {
            foreach ($keys as $kind => $values) {
                if ($values) {
                    $q->orWhere(fn ($w) => $w->where('kind', $kind)->whereIn('value_norm', array_unique($values)));
                }
            }
        })->get(['kind', 'value_norm', 'reason'])->each(function ($s) use (&$hits) {
            $hits[$s->kind . ':' . $s->value_norm] = $s->reason;
        });

        $out = [];
        foreach ($items as $i) {
            foreach (['email' => $i['email'] ?? null, 'phone' => $i['phone_e164'] ?? null, 'domain' => $i['domain_norm'] ?? null] as $kind => $v) {
                $reason = $v ? ($hits["{$kind}:{$v}"] ?? null) : null;
                if ($reason && (($out[$i['source_record_id']] ?? null) !== 'deleted')) {
                    $out[$i['source_record_id']] = $reason;
                }
            }
        }
        return $out;
    }
}
