<?php

namespace App\Services\LeadGen;

use App\Models\Contact;
use App\Models\Lead;
use App\Models\LeadGen\Event;
use App\Models\LeadGen\LeadGenModel;
use App\Models\LeadGen\Prospect;
use App\Services\Crm\ContactResolver;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * "Add to CRM": a prospect becomes a Contact plus a Lead on the existing
 * Leads board. No second CRM.
 *
 * The contact goes through ContactResolver with the email (or phone) as its
 * handle — exactly the identity an inbound email or WhatsApp from that
 * business resolves to, so a reply lands on this same contact instead of
 * creating a stranger. An existing contact with the same email or phone is
 * reused, never duplicated.
 *
 * Idempotent: a prospect already added returns its contact and lead.
 */
class CrmSync
{
    public function __construct(private ContactResolver $contacts) {}

    /** @return array{contact: Contact, lead: Lead, created: bool} */
    public function add(Prospect $p, ?int $actorId = null): array
    {
        if (!ContactResolver::available()) {
            throw new RuntimeException('The CRM isn’t set up for this project yet.');
        }
        $projectId = LeadGenModel::currentProjectId();

        return DB::connection('tenant')->transaction(function () use ($p, $projectId, $actorId) {
            $p = Prospect::whereKey($p->id)->lockForUpdate()->firstOrFail();

            if ($p->lead_id && ($lead = Lead::find($p->lead_id)) && $p->contact_id && ($contact = Contact::find($p->contact_id))) {
                return ['contact' => $contact, 'lead' => $lead, 'created' => false];
            }

            $contact = $p->contact_id ? Contact::find($p->contact_id) : null;
            $details = array_filter([
                'name'  => $p->business_name,
                'email' => $p->email,
                'phone' => $p->phone_e164,
            ]);

            if (!$contact && ($p->email || $p->phone_e164)) {
                $contact = $this->contacts->resolve(
                    projectId: $projectId,
                    channel: $p->email ? 'email' : 'phone',
                    externalId: $p->email ?: ltrim((string) $p->phone_e164, '+'),
                    details: $details,
                );
            } elseif ($contact) {
                $this->contacts->reconcile($contact, $details);
            }
            if (!$contact) {
                $now = time();
                $contact = Contact::create(['project_id' => $projectId, 'name' => $p->business_name,
                    'first_seen_at' => $now, 'last_seen_at' => $now]);
                $contact->forceFill(['created_at' => $now])->save();
            }

            $contact->metadata = ['lead_generation' => array_filter([
                'prospect_id' => $p->id,
                'website'     => $p->website,
                'category'    => $p->category,
                'city'        => $p->city,
                'country'     => $p->country_code,
                'source'      => $p->source_provider,
            ])] + (array) $contact->metadata;
            $contact->save();

            $lead = Lead::where('contact_id', $contact->id)->whereNull('session_id')
                ->where('fields->prospect_id', $p->id)->first();
            if (!$lead) {
                $now = time();
                $lead = Lead::create([
                    'project_id' => $projectId,
                    'contact_id' => $contact->id,
                    'status'     => 'new',
                    'fields'     => array_filter([
                        'name'        => $p->business_name,
                        'email'       => $p->email,
                        'phone'       => $p->phone_e164,
                        'website'     => $p->website,
                        'category'    => $p->category,
                        'city'        => $p->city,
                        'source'      => 'Lead Generation',
                        'prospect_id' => $p->id,
                    ]),
                ]);
                $lead->forceFill(['created_at' => $now, 'update_at' => $now, 'created_by' => $actorId])->save();
            }

            $p->forceFill(['contact_id' => $contact->id, 'lead_id' => $lead->id, 'status' => 'in_crm'])->save();
            Event::log('prospect', $p->id, 'crm.added', ['contact_id' => $contact->id, 'lead_id' => $lead->id]);

            return ['contact' => $contact, 'lead' => $lead, 'created' => true];
        });
    }
}
