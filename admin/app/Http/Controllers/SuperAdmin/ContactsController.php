<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ContactLead;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Website contacts — inbound "Call me now" / contact-form captures from the
 * public marketing site, for super-admin triage. Read + status-update only.
 */
class ContactsController extends Controller
{
    public function index(Request $request): View
    {
        $status = (string) $request->query('status', '');
        $source = (string) $request->query('source', '');
        $search = trim((string) $request->query('q', ''));

        // Sources are written by the public endpoints, not enumerated anywhere,
        // so build the filter list from what's actually in the table.
        $sources = ContactLead::query()->select('source')->distinct()->orderBy('source')->pluck('source')->all();

        $q = ContactLead::query();

        if (in_array($status, ContactLead::STATUSES, true)) {
            $q->where('status', $status);
        }
        if ($source !== '' && in_array($source, $sources, true)) {
            $q->where('source', $source);
        }
        if ($search !== '') {
            $like = '%' . $search . '%';
            $q->where(function ($w) use ($like) {
                $w->where('phone', 'like', $like)
                  ->orWhere('name', 'like', $like)
                  ->orWhere('email', 'like', $like)
                  ->orWhere('message', 'like', $like)
                  ->orWhere('ip', 'like', $like);
            });
        }

        $contacts = $q->orderByDesc('created_at')->paginate(25)->withQueryString();

        // Pill counts respect the source filter (but not status — each pill IS
        // a status) so the numbers match what clicking a pill will show.
        $countsQuery = fn () => ContactLead::query()
            ->when($source !== '' && in_array($source, $sources, true), fn ($w) => $w->where('source', $source));

        $counts = [
            'total'     => $countsQuery()->count(),
            'new'       => $countsQuery()->where('status', 'new')->count(),
            'contacted' => $countsQuery()->where('status', 'contacted')->count(),
            'closed'    => $countsQuery()->where('status', 'closed')->count(),
        ];

        return view('ops.contacts.index', compact('contacts', 'status', 'source', 'sources', 'search', 'counts'));
    }

    public function updateStatus(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'status' => 'required|in:new,contacted,closed',
        ]);

        $contact = ContactLead::findOrFail($id);
        $from    = $contact->status;
        $contact->status = $data['status'];
        $contact->save();

        // Triage is shared work — the audit trail is how one operator sees
        // that another already picked a lead up.
        AuditLog::record('contact.status', [
            'target_type' => 'contact_lead',
            'target_id'   => $contact->id,
            'payload'     => ['from' => $from, 'to' => $data['status']],
        ]);

        return back()->with('status', 'Contact marked as ' . $data['status'] . '.');
    }
}
