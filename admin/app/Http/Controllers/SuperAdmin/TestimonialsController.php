<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Testimonial;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Super-admin CRUD for the homepage testimonial carousel.
 *
 * The section-level copy (eyebrow / heading / lead) is edited with the rest
 * of the landing page in /admin/content; this screen owns the rows the
 * carousel scrolls through.
 */
class TestimonialsController extends Controller
{
    /** Shared validation rules for store + update. */
    private function rules(): array
    {
        return [
            'name'       => 'required|string|max:120',
            'role'       => 'nullable|string|max:120',
            'company'    => 'nullable|string|max:120',
            'quote'      => 'required|string|max:600',
            'rating'     => 'required|integer|min:1|max:5',
            'sort_order' => 'nullable|integer|min:0|max:65535',
            // Not `url`: an uploaded avatar is stored as a site-relative
            // path (/storage/...), which the url rule rejects. Shape is
            // checked in avatarFrom() instead.
            'avatar_url' => 'nullable|string|max:500',
            'avatar'     => 'nullable|image|mimes:jpg,jpeg,png,webp,gif|max:2048',
        ];
    }

    /**
     * Resolve the avatar for a save: an uploaded file wins, otherwise the
     * URL field is used verbatim. Clearing the URL field (and uploading
     * nothing) removes the photo — that is the "remove" affordance.
     */
    private function avatarFrom(Request $request): ?string
    {
        if ($request->hasFile('avatar') && $request->file('avatar')->isValid()) {
            $file = $request->file('avatar');
            $ext  = strtolower($file->getClientOriginalExtension() ?: 'jpg');
            $name = 'testimonial-' . substr(md5($file->getClientOriginalName() . microtime(true)), 0, 12) . '.' . $ext;

            return Storage::url($file->storeAs('site/testimonials', $name, 'public'));
        }

        $url = trim((string) $request->input('avatar_url', ''));
        if ($url === '') {
            return null;
        }

        // Only absolute http(s) or site-relative paths — never javascript:
        // or data:, which would render straight into an <img src> on the
        // public homepage.
        if (! preg_match('#^(https?://|/)#i', $url)) {
            return null;
        }

        return $url;
    }

    public function index(Request $request): View
    {
        $title        = 'Testimonials';
        $testimonials = Testimonial::query()->ordered()->get();

        return view('ops.testimonials.index', compact('title', 'testimonials'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules());

        $t = new Testimonial();
        $t->name       = $data['name'];
        $t->role       = $data['role'] ?? null;
        $t->company    = $data['company'] ?? null;
        $t->quote      = $data['quote'];
        $t->rating     = $data['rating'];
        $t->avatar_url = $this->avatarFrom($request);
        $t->is_active  = $request->boolean('is_active', true);
        // Blank order → drop it at the end of the carousel.
        $t->sort_order = $data['sort_order'] ?? ((int) Testimonial::query()->max('sort_order') + 10);
        $t->save();

        AuditLog::record('testimonial.create', [
            'target_type' => 'testimonial',
            'target_id'   => $t->id,
            'payload'     => ['name' => $t->name],
        ]);

        return back()->with('success', "Testimonial from {$t->name} added — it's live on the homepage.");
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $t    = Testimonial::findOrFail($id);
        $data = $request->validate($this->rules());

        $t->name       = $data['name'];
        $t->role       = $data['role'] ?? null;
        $t->company    = $data['company'] ?? null;
        $t->quote      = $data['quote'];
        $t->rating     = $data['rating'];
        $t->avatar_url = $this->avatarFrom($request);
        $t->is_active  = $request->boolean('is_active');
        $t->sort_order = $data['sort_order'] ?? $t->sort_order;
        $t->save();

        AuditLog::record('testimonial.update', [
            'target_type' => 'testimonial',
            'target_id'   => $t->id,
            'payload'     => ['name' => $t->name],
        ]);

        return back()->with('success', "Testimonial from {$t->name} updated.");
    }

    /** Show / hide a single testimonial without deleting it. */
    public function toggle(Request $request, int $id): RedirectResponse
    {
        $t = Testimonial::findOrFail($id);
        $t->is_active = ! $t->is_active;
        $t->save();

        AuditLog::record('testimonial.toggle', [
            'target_type' => 'testimonial',
            'target_id'   => $t->id,
            'payload'     => ['is_active' => $t->is_active],
        ]);

        return back()->with('success', $t->name . ' is now ' . ($t->is_active ? 'visible' : 'hidden') . ' on the homepage.');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $t    = Testimonial::findOrFail($id);
        $name = $t->name;

        // Drop the uploaded photo with the row so deleted testimonials don't
        // leave orphaned files behind. External URLs are left alone.
        if ($t->avatar_url && str_starts_with($t->avatar_url, '/storage/')) {
            try {
                Storage::disk('public')->delete(substr($t->avatar_url, strlen('/storage/')));
            } catch (\Throwable $e) {
                // Best-effort cleanup — never block the delete on it.
            }
        }

        $t->delete();

        AuditLog::record('testimonial.delete', [
            'target_type' => 'testimonial',
            'target_id'   => $id,
            'payload'     => ['name' => $name],
        ]);

        return back()->with('success', "Testimonial from {$name} deleted.");
    }
}
