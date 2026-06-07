<?php

namespace App\Models;

use App\Models\Concerns\IntSoftDeletes;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    use IntSoftDeletes;

    // Lives in the master DB. Pin explicitly so cross-connection relations
    // (e.g. Session->project from a tenant DB) don't try `tenant.projects`.
    protected $connection = 'mysql';

    protected $fillable = [
        'name',
        'api_key',
        'client_id',
        'url',
        'project_api_key',
        'project_api_secret',
        'db_type',
        'db_host',
        'db_port',
        'db_name',
        'db_user',
        'db_password',
        'json_data',
        'schema',
    ];

    protected $casts = [
        'schema'     => 'array',
        'json_data'  => 'array',
        'db_schema'  => 'array',
        'created_at' => 'integer',
        'updated_at' => 'integer',
        'deleted_at' => 'integer',
    ];
    public $timestamps = false;

    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->getTimestamp();
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'project_users', 'project_id', 'user_id')
                    ->withPivot('client_id')
                    ->withTimestamps();
    }

    public function clients()
    {
        return $this->belongsToMany(Client::class, 'project_users', 'project_id', 'client_id')
                    ->withPivot('user_id')
                    ->withTimestamps();
    }
}
