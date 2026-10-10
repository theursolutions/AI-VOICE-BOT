<?php

namespace App\Models;

use App\Models\Concerns\IntSoftDeletes;
use Illuminate\Database\Eloquent\Model;

class Voice extends Model
{
    use IntSoftDeletes;

    protected $connection = 'tenant';
    protected $table = 'voices';

    protected $fillable = [
        'project_id',
        'provider',
        'name',
        'reference_url',
        'external_id',
        'language',
        'status',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'integer',
        'updated_at' => 'integer',
        'deleted_at' => 'integer',
    ];

    public $timestamps = false;

    /** A ready-made Google voice: no reference audio, external_id = its name ("Kore"). */
    public const PROVIDER_GOOGLE = 'google';

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function isGoogle(): bool
    {
        return $this->provider === self::PROVIDER_GOOGLE;
    }

    /** Ready to speak: a cloned voice needs its reference audio, a Google voice only its name. */
    public function isUsable(): bool
    {
        if ($this->status !== 'ready') {
            return false;
        }

        return $this->isGoogle() ? ! empty($this->external_id) : ! empty($this->reference_url);
    }
}
