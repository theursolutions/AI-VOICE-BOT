<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientProjectUser extends Model
{
    protected $table = 'project_users';
    public $timestamps = false;

    protected $fillable = [
        'client_id',
        'user_id',
    ];

    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->getTimestamp();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
