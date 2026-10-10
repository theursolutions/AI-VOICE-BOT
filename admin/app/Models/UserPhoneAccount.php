<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A user's softphone login — see SoftphoneController. One per user.
 */
class UserPhoneAccount extends Model
{
    protected $fillable = ['user_id', 'extension', 'sip_password', 'ws_url', 'display_name'];

    protected $casts = ['sip_password' => 'encrypted'];

    protected $hidden = ['sip_password'];
}
