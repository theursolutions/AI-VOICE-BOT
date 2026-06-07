<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Per-project "Profile" — the business identity behind each project:
 * logo, public name, website, support contact, industry, timezone,
 * tagline. The logo + name show up in the topbar. The about/industry
 * fields can also be injected into the bot's system prompt so the
 * agent knows what business it represents.
 *
 * Storage lives under projects.json_data['profile']. No migrations
 * needed.
 */
class ProjectProfileController extends Controller
{
    public const DEFAULTS = [
        'website'        => '',
        'support_email'  => '',
        'support_phone'  => '',
        'industry'       => '',
        'timezone'       => 'UTC',
        'language'       => 'en',
        'about'          => '',
        'business_hours' => '',
        'logo_path'      => null,
    ];

    public function index(Request $request, Client $client): View
    {
        $projects = Project::where('client_id', $client->id)
            ->orderBy('name')
            ->get(['id', 'name', 'json_data']);

        $projectId = (int) ($request->query('project_id') ?: optional($projects->first())->id);
        $project = $projects->firstWhere('id', $projectId);

        $profile = $project
            ? array_merge(self::DEFAULTS, (array) data_get($project->json_data, 'profile', []))
            : self::DEFAULTS;

        // Build the public URL relative to the running script dir, so
        // it works even when APP_URL doesn't include /admin/public.
        $logoUrl = null;
        if (!empty($profile['logo_path'])) {
            $scheme = request()->getSchemeAndHttpHost();
            $dir    = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
            $base   = $scheme . rtrim($dir, '/');
            $logoUrl = $base . '/storage/' . ltrim($profile['logo_path'], '/');
        }

        return view('project-profile.index', compact(
            'client', 'projects', 'project', 'projectId', 'profile', 'logoUrl'
        ));
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate([
            'project_id'     => 'required|integer',
            'name'           => 'required|string|max:120',
            'website'        => 'nullable|url|max:255',
            'support_email'  => 'nullable|email|max:255',
            'support_phone'  => 'nullable|string|max:60',
            'industry'       => 'nullable|string|max:120',
            'timezone'       => 'nullable|string|max:80',
            'language'       => 'nullable|string|max:10',
            'about'          => 'nullable|string|max:1000',
            'business_hours' => 'nullable|string|max:500',
            'logo'           => 'nullable|file|mimes:jpg,jpeg,png,gif,webp,svg|max:2048',
            'remove_logo'    => 'nullable|boolean',
        ]);

        $project = Project::where('client_id', $client->id)
            ->where('id', $data['project_id'])
            ->firstOrFail();

        // Update the canonical project name (separate column).
        if (trim($data['name']) !== '') {
            $project->name = trim($data['name']);
        }

        $json = is_array($project->json_data) ? $project->json_data : [];
        $existing = (array) data_get($json, 'profile', []);

        // Handle logo upload / removal. Defensive everywhere — old rows
        // sometimes carry empty strings, leading slashes, or null bytes
        // in logo_path; Flysystem throws "Path cannot be empty" if any
        // of those reach the disk driver. Always normalise + guard.
        $logoPath = $existing['logo_path'] ?? null;
        $logoPath = is_string($logoPath) ? trim($logoPath, " /\t\n\r\0\x0B") : null;
        if ($logoPath === '') $logoPath = null;

        $safeDelete = function (?string $p): void {
            if (!is_string($p) || trim($p) === '') return;
            try {
                Storage::disk('public')->delete($p);
            } catch (\Throwable $e) {
                Log::warning('project-profile: logo delete failed', [
                    'path' => $p, 'err' => $e->getMessage(),
                ]);
            }
        };

        if ($request->boolean('remove_logo') && $logoPath) {
            $safeDelete($logoPath);
            $logoPath = null;
        }

        if ($request->hasFile('logo') && $request->file('logo')->isValid()) {
            $safeDelete($logoPath);
            $ext = strtolower($request->file('logo')->getClientOriginalExtension() ?: 'png');
            $filename = sprintf('%d-%d.%s', $project->id, time(), $ext);
            try {
                $request->file('logo')->storeAs('project-logos', $filename, 'public');
                $logoPath = 'project-logos/' . $filename;
            } catch (\Throwable $e) {
                Log::error('project-profile: logo upload failed', [
                    'err' => $e->getMessage(),
                ]);
                return back()->withInput()->withErrors([
                    'logo' => 'Couldn\'t save the logo: ' . $e->getMessage(),
                ]);
            }
        }

        $json['profile'] = array_merge(self::DEFAULTS, $existing, [
            'website'        => $data['website']        ?? '',
            'support_email'  => $data['support_email']  ?? '',
            'support_phone'  => $data['support_phone']  ?? '',
            'industry'       => $data['industry']       ?? '',
            'timezone'       => $data['timezone']       ?: 'UTC',
            'language'       => $data['language']       ?: 'en',
            'about'          => $data['about']          ?? '',
            'business_hours' => $data['business_hours'] ?? '',
            'logo_path'      => $logoPath,
        ]);

        $project->json_data = $json;
        $project->save();

        return redirect()
            ->route('project-profile.index', ['client' => $client->slug, 'project_id' => $project->id])
            ->with('success', "{$project->name} profile saved.");
    }
}
