<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BotAgent;
use App\Models\Client;
use App\Models\Project;
use App\Models\Voice;
use App\Services\Tenant\TenantManager;
use App\Services\Voice\VoiceResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

        // The "Response voice" card: Google (default) or one of the
        // project's own cloned voices.
        $googleVoices = (array) config('services.voice.google_voices', []);
        $ownVoices    = $voices->filter(fn (Voice $v) => ! $v->isGoogle() && $v->isUsable())->values();
        $response     = $project ? app(VoiceResolver::class)->forProject(Project::find($project->id)) : null;

        // AI agents with a voice of their own ignore the project setting —
        // say so on the card, or picking a voice here looks like it did nothing.
        $overridingAgents = $project
            ? BotAgent::where('project_id', $project->id)
                ->where('type', BotAgent::TYPE_AI)
                ->whereNotNull('voice_id')
                ->get(['id', 'name', 'voice_id'])
            : collect();

        return view('voices.index', compact(
            'client', 'projects', 'project', 'projectId', 'voices', 'languages',
            'googleVoices', 'ownVoices', 'response', 'overridingAgents'
        ));
    }

    /**
     * POST /voices/response — which voice answers this project's calls and
     * widget: a Google voice (the default) or one of its own cloned voices.
     * An agent with its own voice still overrides this.
     */
    public function saveResponseVoice(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate([
            'project_id'   => 'required|integer',
            'source'       => 'required|in:google,own',
            'google_voice' => 'nullable|string|max:40',
            'voice_id'     => 'nullable|integer',
        ]);

        $project = Project::where('client_id', $client->id)
            ->where('id', $data['project_id'])
            ->firstOrFail();
        $this->tenants->useFor($project);

        if ($data['source'] === 'google') {
            $name = (string) ($data['google_voice'] ?? '');
            if (! array_key_exists($name, (array) config('services.voice.google_voices', []))) {
                return back()->withErrors(['google_voice' => 'Pick one of the Google voices.']);
            }
            $setting = ['source' => 'google', 'google_voice' => $name];
            $label   = "the Google voice \"{$name}\"";
        } else {
            $voice = Voice::find((int) ($data['voice_id'] ?? 0));
            if (! $voice || (int) $voice->project_id !== (int) $project->id || $voice->isGoogle() || ! $voice->isUsable()) {
                return back()->withErrors(['voice_id' => 'Pick one of your ready cloned voices.']);
            }
            $setting = ['source' => 'own', 'voice_id' => $voice->id];
            $label   = "your voice \"{$voice->name}\"";
        }

        $this->saveSetting($project, $setting);

        return redirect()
            ->route('voices.index', ['client' => $client->slug, 'project_id' => hashid($project->id)])
            ->with('success', "Calls and the widget now answer in {$label}.");
    }

    /**
     * GET /voices/google-preview?voice=Kore — a short sample of a Google
     * voice. Made once per voice and cached: every preview would otherwise
     * spend Google quota (the free tier allows ~3 a minute).
     */
    public function googlePreview(Request $request, Client $client)
    {
        $name = (string) $request->query('voice', '');
        abort_unless(array_key_exists($name, (array) config('services.voice.google_voices', [])), 404);

        $dir  = storage_path('app/voice-previews');
        $path = $dir . DIRECTORY_SEPARATOR . "google-{$name}.wav";

        if (! is_file($path) || filesize($path) < 1024) {
            $resp = Http::asMultipart()
                ->timeout(30)
                ->post(rtrim((string) config('services.python.base_url'), '/') . '/tts', [
                    ['name' => 'text',     'contents' => "Hello! I'm {$name}. This is how I will sound when I answer your customers."],
                    ['name' => 'provider', 'contents' => 'google'],
                    ['name' => 'voice',    'contents' => $name],
                ]);

            if (! $resp->successful()) {
                return response()->json([
                    'message' => $resp->status() === 429
                        ? 'Google voice quota reached — try again in a minute.'
                        : 'Preview unavailable — is the voice engine running with a Gemini key?',
                ], $resp->status() === 429 ? 429 : 502);
            }

            File::ensureDirectoryExists($dir);
            file_put_contents($path, $resp->body());
        }

        $file = response()->file($path);
        $file->headers->set('Content-Type', 'audio/wav');
        $file->headers->set('Cache-Control', 'private, max-age=86400');
        return $file;
    }

    /** Store the project's response-voice setting and drop greetings made in the old voice. */
    private function saveSetting(Project $project, array $setting): void
    {
        $json = is_array($project->json_data) ? $project->json_data : [];
        $json['voice'] = $setting;
        $project->json_data = $json;
        $project->save();

        try {
            app(\App\Services\Telephony\WelcomeAudioService::class)->invalidateForProject($project->id);
        } catch (\Throwable $e) {
            // Non-fatal — the cache key embeds the voice, so it self-invalidates.
        }
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
        //
        // This move is the step that fails when the speakers dir isn't
        // writable (the classic symptom of the storage-permission bug). It
        // used to be unguarded: the exception escaped AFTER the row was
        // created, leaving a voice stuck at `training` with a null
        // reference_url — visible on this page, silently unusable
        // everywhere else. Now a failure cleans up and says so.
        try {
            $upload->move(dirname($absPath), basename($absPath));
        } catch (\Throwable $e) {
            Log::error('VoiceWebController: speaker upload failed to move', [
                'project_id' => $project->id,
                'voice_id'   => $voice->id,
                'dest'       => $absPath,
                'writable'   => is_writable(dirname($absPath)),
                'err'        => $e->getMessage(),
            ]);

            // Don't leave a half-created voice behind for the user to trip over.
            $voice->forceDelete();

            return back()
                ->withInput()
                ->withErrors(['speaker' => 'Could not save the audio file — the voice was not created. '
                    . 'The speakers directory is not writable on the server ('
                    . dirname($absPath) . '). Check permissions and try again.']);
        }

        // Guard against a "successful" move that wrote nothing (full disk,
        // silently-failing mount). A zero-byte speaker clones no voice.
        if (!is_file($absPath) || filesize($absPath) < 1024) {
            Log::error('VoiceWebController: speaker file missing or too small after move', [
                'project_id' => $project->id, 'voice_id' => $voice->id,
                'path' => $absPath, 'size' => @filesize($absPath),
            ]);
            @unlink($absPath);
            $voice->forceDelete();

            return back()
                ->withInput()
                ->withErrors(['speaker' => 'The uploaded audio did not save correctly on the server. '
                    . 'The voice was not created — please try again.']);
        }

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

        // The star is the same choice as the "Response voice" card — keep
        // them in step, or a saved Google setting would silently win.
        // Also drops the cached phone welcome made in the old voice.
        $this->saveSetting($project, $voice->isGoogle()
            ? ['source' => 'google', 'google_voice' => (string) $voice->external_id]
            : ['source' => 'own', 'voice_id' => $voice->id]);

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
