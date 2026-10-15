<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\PublishSocialPost;
use App\Models\Client;
use App\Models\Project;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Services\Social\CaptionWriter;
use App\Services\Social\SocialMedia;
use App\Support\Hashid;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Msd\MetaChannels\Models\ChannelConnection;

/**
 * Social Posts — compose, schedule and publish to a project's connected
 * Facebook Pages and Instagram accounts, with an AI caption writer.
 *
 * The accounts are the same ChannelConnection rows the inbox uses. Publishing
 * needs extra permissions on top of messaging, which the "Grant publishing"
 * button asks for (ChannelOnboardController with purpose=publish).
 *
 * Project-scoped and in the app DB, like the Channels page.
 */
class SocialPostController extends Controller
{
    private const MAX_ITEMS = 10;

    /** Post-list tabs: key => statuses shown (null = everything). */
    public const FILTERS = [
        'all'       => null,
        'scheduled' => [SocialPost::STATUS_SCHEDULED, SocialPost::STATUS_PUBLISHING],
        'published' => [SocialPost::STATUS_PUBLISHED, SocialPost::STATUS_PARTIAL],
        'draft'     => [SocialPost::STATUS_DRAFT],
        'failed'    => [SocialPost::STATUS_FAILED, SocialPost::STATUS_PARTIAL],
    ];

    public function __construct(private SocialMedia $media) {}

    public function index(Request $request, Client $client): View
    {
        $projects = Project::where('client_id', $client->id)->orderBy('name')->get(['id', 'name', 'json_data']);

        $projectId = (int) ($request->query('project_id') ?: optional($projects->first())->id);
        $project   = $projects->firstWhere('id', $projectId);

        $filter = array_key_exists((string) $request->query('filter'), self::FILTERS) ? (string) $request->query('filter') : 'all';

        $accounts = collect();
        $posts    = null;
        $counts   = [];
        if ($project) {
            $accounts = $this->accounts($project);
            $posts = SocialPost::with('targets')
                ->where('project_id', $project->id)
                ->when(self::FILTERS[$filter] ?? null, fn ($q, $statuses) => $q->whereIn('status', $statuses))
                ->orderByRaw("CASE WHEN status = 'scheduled' THEN 0 ELSE 1 END")
                ->orderByDesc('id')
                ->paginate(15)
                ->withQueryString();
            $counts = SocialPost::where('project_id', $project->id)
                ->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status')->all();
        }

        return view('social.index', [
            'client'    => $client,
            'projects'  => $projects,
            'project'   => $project,
            'projectId' => $projectId,
            'accounts'  => $accounts,
            'posts'     => $posts,
            'counts'    => $counts,
            'filter'    => $filter,
            'timezone'  => $project ? $this->timezone($project) : config('app.timezone'),
            'formats'   => SocialPost::FORMATS,
        ]);
    }

    public function store(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate([
            'project_id'   => 'required|integer',
            'format'       => 'required|in:' . implode(',', array_keys(SocialPost::FORMATS)),
            'caption'      => 'nullable|string|max:2200',
            'link'         => 'nullable|url|max:500',
            'accounts'     => 'nullable|array',
            'accounts.*'   => 'string|max:40',
            'media'        => 'nullable|array|max:' . self::MAX_ITEMS,
            // 8 MB is Instagram's image ceiling; 50 MB matches the upload
            // limit nginx and PHP are configured for in production.
            'media.*'      => 'file|mimetypes:image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime|max:51200',
            'action'       => 'required|in:draft,schedule,now',
            'scheduled_at' => 'nullable|required_if:action,schedule|date',
            'ai_generated' => 'nullable|boolean',
        ], [
            'media.*.mimetypes' => 'Media must be JPG, PNG, WebP or GIF images, or MP4/MOV videos.',
            'media.*.max'       => 'Each file can be up to 50 MB.',
        ]);

        $project = $this->guard($client, (int) $data['project_id']);
        $back    = fn () => back()->withInput();

        // Accounts are submitted as hashed ids; anything not belonging to this
        // project is dropped rather than trusted.
        $ids = collect($data['accounts'] ?? [])->map(fn ($v) => Hashid::decode((string) $v))->filter()->all();
        $accounts = $this->accounts($project)->whereIn('id', $ids)->values();

        if ($data['action'] !== 'draft' && $accounts->isEmpty()) {
            return $back()->with('error', 'Pick at least one account to post to.');
        }

        $files = $request->file('media', []);
        foreach ($files as $f) {
            if (str_starts_with((string) $f->getMimeType(), 'image/') && $f->getSize() > 8 * 1024 * 1024) {
                return $back()->with('error', 'Images can be up to 8 MB each (Instagram’s limit).');
            }
        }

        if ($problem = $this->checkShape($data['format'], $files, $accounts, (string) ($data['caption'] ?? ''), $data['link'] ?? null, $data['action'])) {
            return $back()->with('error', $problem);
        }

        $scheduledAt = null;
        if ($data['action'] === 'schedule') {
            $scheduledAt = Carbon::parse($data['scheduled_at'], $this->timezone($project))->utc();
            if ($scheduledAt->lt(now()->subMinute())) {
                return $back()->with('error', 'The scheduled time is in the past.');
            }
        }

        $stored = [];
        try {
            foreach ($files as $f) {
                $stored[] = $this->media->store($f, $project->id);
            }
        } catch (\Throwable $e) {
            $this->media->delete($stored);
            return $back()->with('error', $e->getMessage());
        }

        $post = DB::transaction(function () use ($project, $data, $stored, $accounts, $scheduledAt) {
            $post = SocialPost::create([
                'project_id'   => $project->id,
                'user_id'      => auth()->id(),
                'format'       => $data['format'],
                'caption'      => trim((string) ($data['caption'] ?? '')) ?: null,
                'link'         => $data['link'] ?? null,
                'media'        => $stored,
                'status'       => match ($data['action']) {
                    'now'      => SocialPost::STATUS_PUBLISHING,
                    'schedule' => SocialPost::STATUS_SCHEDULED,
                    default    => SocialPost::STATUS_DRAFT,
                },
                'scheduled_at' => $scheduledAt,
                'ai_generated' => (bool) ($data['ai_generated'] ?? false),
            ]);

            foreach ($accounts as $conn) {
                $post->targets()->create([
                    'channel_connection_id' => $conn->id,
                    'provider'              => $conn->provider,
                    'account_name'          => $conn->name,
                    'status'                => SocialPostTarget::STATUS_PENDING,
                ]);
            }

            return $post;
        });

        $message = match ($data['action']) {
            'now'      => 'Publishing now. Instagram videos can take a minute or two to appear.',
            'schedule' => 'Scheduled for ' . $scheduledAt->copy()->setTimezone($this->timezone($project))->format('D j M, g:i A') . '.',
            default    => 'Saved as a draft.',
        };

        if ($data['action'] === 'now') {
            PublishSocialPost::dispatch($post->id);
        }

        return redirect()
            ->route('social-posts.index', ['client' => $client->slug, 'project_id' => hashid($project->id)])
            ->with('success', $message);
    }

    /** Publish a draft or scheduled post right away. */
    public function publishNow(Request $request, Client $client, int $id): RedirectResponse
    {
        $post = $this->findPost($client, $id);

        if (! $post->isEditable()) {
            return back()->with('error', 'This post has already been published.');
        }
        if (! $post->targets()->exists()) {
            return back()->with('error', 'This draft has no accounts to post to. Create it again with accounts selected.');
        }

        $post->update(['status' => SocialPost::STATUS_PUBLISHING]);
        PublishSocialPost::dispatch($post->id);

        return back()->with('success', 'Publishing now.');
    }

    /** Re-try only the accounts that failed. The ones that worked are left alone. */
    public function retry(Request $request, Client $client, int $id): RedirectResponse
    {
        $post = $this->findPost($client, $id);

        $n = $post->targets()->where('status', SocialPostTarget::STATUS_FAILED)->update([
            'status' => SocialPostTarget::STATUS_PENDING,
            'state'  => null,
            'error'  => null,
        ]);
        if (! $n) {
            return back()->with('error', 'Nothing to retry on this post.');
        }

        $post->update(['status' => SocialPost::STATUS_PUBLISHING]);
        PublishSocialPost::dispatch($post->id);

        return back()->with('success', "Retrying {$n} account(s).");
    }

    /**
     * Remove a post from this list. Never touches what is already live on
     * Facebook or Instagram — deleting there is done on the platform.
     */
    public function destroy(Request $request, Client $client, int $id): RedirectResponse
    {
        $post = $this->findPost($client, $id);

        if ($post->status === SocialPost::STATUS_PUBLISHING) {
            return back()->with('error', 'This post is publishing right now. Try again in a minute.');
        }

        $this->media->delete($post->mediaItems());
        $post->delete();

        return back()->with('success', $post->published_at
            ? 'Removed from this list. It is still live on the platforms.'
            : 'Post deleted.');
    }

    /** AI caption — the composer's "Write with AI" button. */
    public function caption(Request $request, Client $client, CaptionWriter $writer): JsonResponse
    {
        $data = $request->validate([
            'project_id' => 'required|integer',
            'brief'      => 'nullable|string|max:1000',
            'draft'      => 'nullable|string|max:2200',
            'format'     => 'nullable|in:' . implode(',', array_keys(SocialPost::FORMATS)),
            'platforms'  => 'nullable|array',
            'platforms.*'=> 'in:facebook_page,instagram',
            'tone'       => 'nullable|string|max:40',
            'language'   => 'nullable|string|max:40',
        ]);

        if (trim((string) ($data['brief'] ?? '')) === '' && trim((string) ($data['draft'] ?? '')) === '') {
            return response()->json(['ok' => false, 'message' => 'Tell the AI what the post is about first.'], 422);
        }

        $project = $this->guard($client, (int) $data['project_id']);

        $platforms = collect($data['platforms'] ?? [])->unique()
            ->map(fn ($p) => $p === 'instagram' ? 'Instagram' : 'Facebook')->values()->all();

        try {
            $caption = $writer->write(
                $project,
                (string) ($data['brief'] ?? ''),
                (string) ($data['draft'] ?? ''),
                $platforms,
                (string) ($data['format'] ?? SocialPost::FORMAT_POST),
                (string) (($data['tone'] ?? '') ?: 'friendly and professional'),
                (string) (($data['language'] ?? '') ?: 'English'),
            );
        } catch (\Throwable $e) {
            Log::warning('Social caption generation failed', ['project' => $project->id, 'error' => $e->getMessage()]);
            return response()->json(['ok' => false, 'message' => 'The AI could not write a caption just now. Please try again.'], 502);
        }

        if ($caption === '') {
            return response()->json(['ok' => false, 'message' => 'The AI returned an empty caption. Try adding more detail.'], 502);
        }

        return response()->json(['ok' => true, 'caption' => $caption]);
    }

    // ── helpers ──────────────────────────────────────────────────────

    /** The project's Facebook Pages and Instagram accounts. */
    private function accounts(Project $project)
    {
        return ChannelConnection::where('project_id', $project->id)
            ->whereIn('provider', [ChannelConnection::PROVIDER_FACEBOOK_PAGE, ChannelConnection::PROVIDER_INSTAGRAM])
            ->orderBy('provider')
            ->orderBy('name')
            ->get();
    }

    /**
     * What each format can carry on each platform, checked before anything
     * is stored — so the person finds out now, not when the schedule fires.
     */
    private function checkShape(string $format, array $files, $accounts, string $caption, ?string $link, string $action): ?string
    {
        $videos = collect($files)->filter(fn ($f) => str_starts_with((string) $f->getMimeType(), 'video/'))->count();
        $total  = count($files);
        $hasFb  = $accounts->contains('provider', ChannelConnection::PROVIDER_FACEBOOK_PAGE);
        $hasIg  = $accounts->contains('provider', ChannelConnection::PROVIDER_INSTAGRAM);

        if ($format === SocialPost::FORMAT_REEL && ($total !== 1 || $videos !== 1)) {
            return 'A Reel needs exactly one video.';
        }
        if ($format === SocialPost::FORMAT_STORY) {
            if ($total !== 1) {
                return 'A Story needs exactly one photo or video.';
            }
            if ($hasFb) {
                return 'Stories can only be published to Instagram. Untick the Facebook Pages.';
            }
        }
        if ($format === SocialPost::FORMAT_POST) {
            if ($hasIg && $total === 0) {
                return 'Instagram needs at least one photo or video.';
            }
            if ($hasFb && $videos > 0 && $total > 1) {
                return 'Facebook can’t mix videos into a multi-photo post. Use one video, or photos only.';
            }
            if ($action !== 'draft' && $total === 0 && trim($caption) === '' && ! $link) {
                return 'Write something or add a photo first.';
            }
        }

        return null;
    }

    /** Scheduling happens in the project's own timezone (Project Profile). */
    private function timezone(Project $project): string
    {
        $tz = (string) data_get($project->json_data, 'profile.timezone', '');

        return in_array($tz, timezone_identifiers_list(), true) ? $tz : (string) config('app.timezone', 'UTC');
    }

    private function findPost(Client $client, int $id): SocialPost
    {
        return SocialPost::whereIn('project_id', Project::where('client_id', $client->id)->pluck('id'))
            ->findOrFail($id);
    }

    private function guard(Client $client, int $projectId): Project
    {
        return Project::where('client_id', $client->id)->where('id', $projectId)->firstOrFail();
    }
}
