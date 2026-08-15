<?php

namespace App\Models;

use App\Models\Concerns\IntSoftDeletes;
use Illuminate\Database\Eloquent\Model;

/**
 * One human, however many channels they reach you on.
 *
 * See the migration for why this exists. The short version: a lead used to
 * hang off a session, a session is scoped to one channel, so one person
 * writing on two channels was two unrelated strangers.
 */
class Contact extends Model
{
    use IntSoftDeletes;

    protected $connection = 'tenant';
    protected $table = 'contacts';

    protected $fillable = [
        'project_id', 'name', 'email', 'phone', 'avatar',
        'notes', 'metadata', 'first_seen_at', 'last_seen_at',
    ];

    protected $casts = [
        'metadata'      => 'array',
        'first_seen_at' => 'integer',
        'last_seen_at'  => 'integer',
        'created_at'    => 'integer',
        'update_at'     => 'integer',
        'deleted_at'    => 'integer',
    ];

    public $timestamps = false;

    public function identities()
    {
        return $this->hasMany(ContactIdentity::class, 'contact_id');
    }

    public function sessions()
    {
        return $this->hasMany(Session::class, 'contact_id');
    }

    public function leads()
    {
        return $this->hasMany(Lead::class, 'contact_id');
    }

    /** What to call them when no name has been captured. */
    public function displayName(): string
    {
        if ($name = trim((string) $this->name)) {
            return $name;
        }
        if ($this->phone) {
            return '+' . preg_replace('/\D+/', '', $this->phone);
        }

        return 'Unknown contact';
    }

    /**
     * Fold a duplicate into this contact.
     *
     * Identities are REPOINTED rather than copied, and messages are never
     * touched — that is the whole reason identities live in their own table.
     * Field-level merge keeps whatever is already set here and only fills
     * gaps from the other record, so a merge can add information but never
     * silently overwrite a value someone typed.
     *
     * Not reversible. Callers must be sure; see ContactResolver for why
     * only email and phone are ever considered strong enough to auto-merge.
     */
    public function absorb(self $other): void
    {
        if ($other->id === $this->id) {
            return;
        }

        foreach (['name', 'email', 'phone', 'avatar', 'notes'] as $field) {
            if (blank($this->{$field}) && filled($other->{$field})) {
                $this->{$field} = $other->{$field};
            }
        }

        $this->first_seen_at = min(
            $this->first_seen_at ?: PHP_INT_MAX,
            $other->first_seen_at ?: PHP_INT_MAX,
        ) ?: null;
        $this->last_seen_at = max((int) $this->last_seen_at, (int) $other->last_seen_at) ?: null;

        // Keep a trace of what was folded in. A merge that cannot be
        // explained later is indistinguishable from a bug.
        $meta = (array) $this->metadata;
        $meta['merged'] = array_values(array_unique(array_merge(
            (array) ($meta['merged'] ?? []),
            [['id' => $other->id, 'name' => $other->name, 'at' => time()]],
            (array) data_get($other->metadata, 'merged', []),
        ), SORT_REGULAR));
        $this->metadata = $meta;

        $this->update_at = time();
        $this->save();

        ContactIdentity::where('contact_id', $other->id)->update(['contact_id' => $this->id]);
        Session::where('contact_id', $other->id)->update(['contact_id' => $this->id]);
        Lead::where('contact_id', $other->id)->update(['contact_id' => $this->id]);

        $other->deleted_at = time();
        $other->save();
    }
}
