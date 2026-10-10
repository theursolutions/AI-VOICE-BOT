<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A client's SIP extension on our Asterisk (Telephony → Extensions).
 *
 * The account is "c<client>_<extension>" so one Asterisk can serve every
 * client; people dial each other by the short extension (the dialplan adds
 * the prefix). See telephony/asterisk/conf/extensions.conf [internal-calls].
 */
class SipExtension extends Model
{
    protected $connection = 'mysql';

    public const KIND_APP    = 'app';     // MicroSIP, Zoiper, a desk phone
    public const KIND_WEBRTC = 'webrtc';  // the admin's browser phone

    public const KINDS = [
        self::KIND_APP    => 'Phone or app (MicroSIP, Zoiper, desk phone)',
        self::KIND_WEBRTC => 'Browser phone (admin panel)',
    ];

    protected $fillable = ['client_id', 'extension', 'name', 'kind', 'sip_username', 'secret',
                           'user_id', 'pushed_at', 'last_error'];

    protected $casts = ['secret' => 'encrypted', 'pushed_at' => 'datetime'];

    protected $hidden = ['secret'];

    public static function usernameFor(int $clientId, string $extension): string
    {
        return 'c' . $clientId . '_' . $extension;
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
