<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A platform event in the super-admin notification panel (the bell): a new
 * user, client, project, website contact or payment. Written by
 * App\Services\Ops\OpsAlerts, which also emails it to the team.
 */
class OpsNotification extends Model
{
    public const UPDATED_AT = null;

    /** Panel tabs, in order. */
    public const KINDS = [
        'user'    => 'Users',
        'client'  => 'Clients',
        'project' => 'Projects',
        'contact' => 'Website contacts',
        'payment' => 'Payments',
    ];

    protected $fillable = ['kind', 'title', 'body', 'url', 'details'];

    protected $casts = ['details' => 'array'];

    /** @return list<array{0: string, 1: string}> label/value pairs, in the order they were written */
    public function detailPairs(): array
    {
        return array_values(array_filter((array) $this->details, fn ($pair) => is_array($pair) && count($pair) === 2));
    }

    public function detail(string $label): ?string
    {
        foreach ($this->detailPairs() as [$l, $v]) {
            if ($l === $label) {
                return $v;
            }
        }

        return null;
    }

    /** Not yet read by this admin. */
    public function scopeUnreadBy(Builder $q, int $userId): Builder
    {
        return $q->whereNotExists(fn ($r) => $r->from('ops_notification_reads')
            ->whereColumn('ops_notification_reads.ops_notification_id', 'ops_notifications.id')
            ->where('ops_notification_reads.user_id', $userId));
    }

    /** Unread counts per kind for this admin, plus 'all'. */
    public static function unreadCounts(int $userId): array
    {
        $byKind = static::query()->unreadBy($userId)
            ->selectRaw('kind, count(*) as n')->groupBy('kind')->pluck('n', 'kind');

        $counts = ['all' => (int) $byKind->sum()];
        foreach (array_keys(self::KINDS) as $kind) {
            $counts[$kind] = (int) ($byKind[$kind] ?? 0);
        }

        return $counts;
    }

    /** Mark everything (or one kind, or the given ids) read for this admin. */
    public static function markRead(int $userId, ?string $kind = null, array $ids = []): void
    {
        $unread = static::query()->unreadBy($userId)
            ->when($kind, fn ($q) => $q->where('kind', $kind))
            ->when($ids, fn ($q) => $q->whereIn('id', $ids))
            ->select('id');

        DB::table('ops_notification_reads')->insertUsing(
            ['ops_notification_id', 'user_id', 'read_at'],
            $unread->toBase()->selectRaw('?, ?', [$userId, now()]),
        );
    }
}
