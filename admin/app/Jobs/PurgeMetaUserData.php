<?php

namespace App\Jobs;

use App\Meta\ContactAvatars;
use App\Models\Message;
use App\Models\Project;
use App\Models\Session;
use App\Services\Tenant\TenantManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Msd\MetaChannels\Models\ChannelConnection;
use Msd\MetaChannels\Models\DataDeletionRequest;

/**
 * Erase everything we hold about one person on one Meta platform.
 *
 * Queued rather than done inline because Meta's callback has a short timeout
 * and the work spans every tenant database the person might appear in —
 * answering slowly would make Meta retry and open duplicate requests.
 *
 * Scope, stated precisely, because "delete my data" is easy to get wrong in
 * both directions:
 *
 *   DELETED   the person's conversations and every message in them, across
 *             every project whose channels could have received them, plus any
 *             cached profile name/photo
 *   KEPT      the DataDeletionRequest row itself — provider, opaque platform
 *             id, timestamps. That is the proof the request was honoured, and
 *             deleting it would make the promise unauditable
 *   UNTOUCHED the business's own channel connections and settings. This is a
 *             request from a customer of our customer, not from the account
 *             holder
 */
class PurgeMetaUserData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    /** sessions.channel values that can hold a Meta platform id. */
    private const META_CHANNELS = ['whatsapp', 'instagram', 'facebook'];

    public function __construct(public int $requestId) {}

    public function handle(TenantManager $tenants, ContactAvatars $avatars): void
    {
        $request = DataDeletionRequest::find($this->requestId);
        if (! $request || $request->status === DataDeletionRequest::STATUS_COMPLETED) {
            return;
        }

        $sessions = 0;
        $messages = 0;

        try {
            foreach ($this->projects() as $project) {
                $tenants->useFor($project);

                // Match on external_id, the platform's own identifier for the
                // person, rather than on any name or phone we may have
                // enriched — those are exactly the fields being erased and are
                // not reliable to search on.
                //
                // Every Meta channel is searched, not just the one recorded on
                // the request. Meta's signed_request does not say which
                // product it came from, so `provider` there is an inference;
                // trusting it would mean a wrong guess silently deletes
                // nothing and reports success. Widening the search cannot
                // over-delete, because the platform id still has to match.
                $ids = Session::where('project_id', $project->id)
                    ->whereIn('channel', self::META_CHANNELS)
                    ->where('external_id', $request->external_user_id)
                    ->pluck('id');

                if ($ids->isEmpty()) {
                    continue;
                }

                // Messages first: no FK cascade exists between the tenant
                // tables, so deleting sessions first would orphan every
                // message rather than remove it.
                $messages += Message::whereIn('session_id', $ids)->delete();
                $sessions += Session::whereIn('id', $ids)->delete();
            }

            // The profile photo is a FILE on our disk, not just a column —
            // ContactAvatars downloads it precisely so it outlives Meta's
            // expiring URL. Deleting the conversation without deleting the
            // image would leave the most personal thing we hold sitting in
            // storage after we told the person it was gone.
            foreach (['instagram', 'facebook_page', 'messenger', 'whatsapp'] as $p) {
                $avatars->forget($p, $request->external_user_id);
            }

            // The webhook caches the display name and avatar for a day. Left
            // behind, a message arriving minutes after the purge would repaint
            // the name we just deleted.
            $this->forgetCachedProfile($request->provider, $request->external_user_id);

            $request->markCompleted($sessions, $messages);

            Log::info('Meta data deletion completed', [
                'request'  => $request->id,
                'provider' => $request->provider,
                'sessions' => $sessions,
                'messages' => $messages,
            ]);
        } catch (\Throwable $e) {
            $request->markFailed($e->getMessage());

            Log::error('Meta data deletion failed', [
                'request' => $request->id,
                'error'   => $e->getMessage(),
            ]);

            throw $e;   // let the queue retry; markFailed keeps the last reason
        } finally {
            $tenants->reset();
        }
    }

    /**
     * Projects that have any Meta channel at all.
     *
     * Narrowed this way rather than sweeping every tenant: a project with no
     * Meta connection cannot hold a PSID/IGSID row, and opening its database
     * would be a wasted connection per project on an install with hundreds.
     */
    private function projects(): iterable
    {
        $ids = ChannelConnection::whereIn('provider', [
            ChannelConnection::PROVIDER_WHATSAPP,
            ChannelConnection::PROVIDER_INSTAGRAM,
            ChannelConnection::PROVIDER_FACEBOOK_PAGE,
            ChannelConnection::PROVIDER_MESSENGER,
        ])->distinct()->pluck('project_id')->filter()->all();

        return $ids ? Project::whereIn('id', $ids)->get() : collect();
    }

    private function forgetCachedProfile(string $provider, string $externalId): void
    {
        foreach ([$provider, 'facebook_page', 'messenger', 'instagram'] as $p) {
            Cache::forget("meta:profile:{$p}:{$externalId}");
        }
    }
}
