<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\OpsNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The super-admin notification panel (the bell): new users, clients,
 * projects, website contacts and payments, one tab each. Events are written
 * by App\Services\Ops\OpsAlerts; read state is per admin.
 */
class NotificationsController extends Controller
{
    /** GET /admin/notifications: every notification, by tab, paginated. */
    public function index(Request $request): View
    {
        $kind = $this->kind($request);
        $userId = (int) $request->user()->id;

        $items = OpsNotification::query()
            ->when($kind, fn ($q) => $q->where('kind', $kind))
            ->orderByDesc('id')->paginate(30)->withQueryString();

        return view('ops.notifications.index', [
            'title'  => 'Notifications',
            'kind'   => $kind,
            'items'  => $items,
            'unread' => $this->unreadIds($userId, $items->pluck('id')->all()),
            'counts' => OpsNotification::unreadCounts($userId),
        ]);
    }

    /** GET /admin/notifications/feed?kind=: the bell's dropdown list. */
    public function feed(Request $request): JsonResponse
    {
        $kind = $this->kind($request);
        $userId = (int) $request->user()->id;

        $items = OpsNotification::query()
            ->when($kind, fn ($q) => $q->where('kind', $kind))
            ->orderByDesc('id')->limit(20)->get();
        $unread = $this->unreadIds($userId, $items->pluck('id')->all());

        return response()->json([
            'counts' => OpsNotification::unreadCounts($userId),
            'items'  => $items->map(fn (OpsNotification $n) => [
                'kind'   => $n->kind,
                'title'  => $n->title,
                'body'   => $n->body,
                'time'   => $n->created_at?->diffForHumans(),
                'unread' => isset($unread[$n->id]),
                'url'    => route('ops.notifications.open', $n->id),
            ])->values(),
        ]);
    }

    /** POST /admin/notifications/read: mark a tab (or everything) read. */
    public function read(Request $request): JsonResponse|RedirectResponse
    {
        OpsNotification::markRead((int) $request->user()->id, $this->kind($request));

        return $request->expectsJson()
            ? response()->json(['counts' => OpsNotification::unreadCounts((int) $request->user()->id)])
            : back()->with('success', 'Marked as read.');
    }

    /** GET /admin/notifications/{id}/open: mark it read, then go where it points. */
    public function open(Request $request, int $id): RedirectResponse
    {
        $n = OpsNotification::findOrFail($id);
        OpsNotification::markRead((int) $request->user()->id, null, [$n->id]);

        return redirect()->to($n->url ?: route('ops.notifications.index', ['kind' => $n->kind]));
    }

    private function kind(Request $request): ?string
    {
        $kind = (string) $request->input('kind', '');

        return array_key_exists($kind, OpsNotification::KINDS) ? $kind : null;
    }

    /** @return array<int, true> ids (of those given) this admin has not read */
    private function unreadIds(int $userId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $read = DB::table('ops_notification_reads')->where('user_id', $userId)
            ->whereIn('ops_notification_id', $ids)->pluck('ops_notification_id')->all();

        return array_fill_keys(array_diff($ids, $read), true);
    }
}
