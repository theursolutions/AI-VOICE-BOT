<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
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
        $search = trim((string) $request->query('q', ''));

        $q = ContactLead::query();

        if (in_array($status, ContactLead::STATUSES, true)) {
            $q->where('status', $status);
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

        $counts = [
            'total'     => ContactLead::count(),
            'new'       => ContactLead::where('status', 'new')->count(),
            'contacted' => ContactLead::where('status', 'contacted')->count(),
            'closed'    => ContactLead::where('status', 'closed')->count(),
        ];

        return view('ops.contacts.index', compact('contacts', 'status', 'search', 'counts'));
    }

    public function updateStatus(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'status' => 'required|in:new,contacted,closed',
        ]);

        $contact = ContactLead::findOrFail($id);
        $contact->status = $data['status'];
        $contact->save();

        return back()->with('status', 'Contact marked as ' . $data['status'] . '.');
    }
}
