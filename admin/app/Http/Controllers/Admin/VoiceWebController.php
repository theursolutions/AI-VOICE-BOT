<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Project;
use App\Models\Voice;
use App\Services\Tenant\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Per-project voice management. Lets a workspace upload speaker
 * reference WAVs (used by Coqui XTTS-v2 for voice cloning) and pick
 * the default voice / language for new sessions.
 */
class VoiceWebController extends Controller
{
    public function __construct(private TenantManager $tenants) {}

    public function index(Request $request, Client $client): View
    {
        $projects = Project::where('client_id', $client->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        $projectId = (int) ($request->query('project_id') ?: optional($projects->first())->id);
        $project = $projects->firstWhere('id', $projectId);

        $voices = collect();
        if ($project) {
            $this->tenants->useFor($project);
            $voices = Voice::where('project_id', $project->id)
                ->orderByDesc('id')
                ->get();
        }

        $languages = config('services.voice.supported_languages');

        return view('voices.index', compact(
            'client', 'projects', 'project', 'projectId', 'voices', 'languages'
        ));
    }

    public function store(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate([
            'project_id' => 'required|integer',
            'name'       => 'required|string|max:120',
            'language'   => 'required|string|max:10',
            'speaker'    => 'required|file|mimetypes:audio/wav,audio/x-wav,audio/mpeg,audio/mp4,audio/x-m4a,audio/webm,video/mp4|max:10240',
        ]);

        $project = Project::where('client_id', $client->id)
            ->where('id', $data['project_id'])
            ->firstOrFail();
        $this->tenants->useFor($project);

        $languages = config('services.voice.supported_languages');
        abort_unless(array_key_exists($data['language'], $languages), 422, 'Unsupported language.');

        $now = time();

        // Create the Voice row first so we have an ID to name the file.
        $voice = Voice::create([
            'project_id'    => $project->id,
            'provider'      => 'coqui',
            'name'          => $data['name'],
            'reference_url' => null,
            'language'      => $data['language'],
            'status'        => 'training',
            'metadata'      => null,
            'created_at'    => $now,
            'update_at'     => $now,
            'is_active'     => 'Yes',
        ]);

        // Move the upload into the shared speakers dir that Python reads.
        $dir = rtrim(config('services.voice.speakers_dir'), '/\\') . DIRECTORY_SEPARATOR . $project->id;
        if (!is_dir($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        /** @var UploadedFile $upload */
        $upload = $data['speaker'];
        $absPath = $dir . DIRECTORY_SEPARATOR . $voice->id . '.wav';

        // If the upload is already a wav, move it. Otherwise we still
        // store it as .wav — Coqui's audio loader handles common formats
        // via soundfile/torchaudio, so this is mostly cosmetic. For
        // best results users should upload mono 24kHz wav.
        $upload->move(dirname($absPath), basename($absPath));

        $voice->reference_url = $absPath;
        $voice->status = 'ready';
        $voice->save();

        return redirect()
            ->route('voices.index', ['client' => $client->slug])
            ->withInput(['project_id' => $project->id])
            ->with('success', "Voice \"{$voice->name}\" uploaded.");
    }

    public function setDefault(Request $request, Client $client, int $id): RedirectResponse
    {
        $data = $request->validate(['project_id' => 'required|integer']);

        $project = Project::where('client_id', $client->id)
            ->where('id', $data['project_id'])
            ->firstOrFail();
        $this->tenants->useFor($project);

        $voice = Voice::findOrFail($id);
        abort_unless((int) $voice->project_id === (int) $project->id, 404);

        // Flip the is_default flag in metadata for all voices in this
        // project; only `$voice` keeps it true.
        Voice::where('project_id', $project->id)->get()->each(function ($v) use ($voice) {
            $meta = $v->metadata ?? [];
            $meta['is_default'] = ((int) $v->id === (int) $voice->id);
            $v->metadata = $meta;
            $v->save();
        });

        // Invalidate the cached phone-welcome audio for this project —
        // it was generated against the old default voice and must be
        // regenerated against the new one.
        try {
            app(\App\Services\Telephony\WelcomeAudioService::class)
                ->invalidateForProject($project->id);
        } catch (\Throwable $e) {
            // Non-fatal — cache key embeds the speaker path, so it'll
            // self-invalidate on next call anyway.
        }

        return redirect()
            ->route('voices.index', ['client' => $client->slug])
            ->withInput(['project_id' => $project->id])
            ->with('success', "\"{$voice->name}\" is now the default voice.");
    }

    /**
     * Stream the reference WAV back to the browser so users can preview
     * a cloned voice. The file lives on disk outside the public web
     * root — Laravel reads it and re-emits with audio/wav headers.
     */
    public function audio(Request $request, Client $client, int $id): BinaryFileResponse
    {
        $projectId = (int) $request->query('project_id');
        $project = Project::where('client_id', $client->id)
            ->where('id', $projectId)
            ->firstOrFail();
        $this->tenants->useFor($project);

        $voice = Voice::findOrFail($id);
        abort_unless((int) $voice->project_id === (int) $project->id, 404);
        abort_unless($voice->reference_url && file_exists($voice->reference_url), 404, 'Audio file missing.');

        $resp = response()->file($voice->reference_url);
        // Force the audio MIME — Symfony's MimeTypeGuesser sometimes
        // returns "application/octet-stream" for WAV files on Windows,
        // which the browser refuses to play.
        $resp->headers->set('Content-Type',  'audio/wav');
        $resp->headers->set('Cache-Control', 'private, max-age=3600');
        $resp->headers->set('Accept-Ranges', 'bytes');
        return $resp;
    }

    public function destroy(Request $request, Client $client, int $id): RedirectResponse
    {
        $data = $request->validate(['project_id' => 'required|integer']);

        $project = Project::where('client_id', $client->id)
            ->where('id', $data['project_id'])
            ->firstOrFail();
        $this->tenants->useFor($project);

        $voice = Voice::findOrFail($id);
        abort_unless((int) $voice->project_id === (int) $project->id, 404);

        if ($voice->reference_url && file_exists($voice->reference_url)) {
            @unlink($voice->reference_url);
        }
        $voice->delete();

        return redirect()
            ->route('voices.index', ['client' => $client->slug])
            ->withInput(['project_id' => $project->id])
            ->with('success', 'Voice removed.');
    }
}
