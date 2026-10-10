<?php

namespace App\Services\Telephony;

use App\Models\SiteSetting;

/**
 * How phones and browsers reach our Asterisk — one server for every
 * client, so a platform setting (Ops → Telephony → SIP server), with
 * config/services.php `asterisk` as the defaults.
 *
 *   ws_url  — browsers: the admin's browser phone and website "Call us"
 *             buttons. Must be wss:// on an https site.
 *   host / port — desk phones and apps (MicroSIP, Zoiper).
 *   ice     — STUN/TURN servers browsers use to get audio through home
 *             and office routers. TURN is what makes it work everywhere.
 */
class SipServer
{
    public const SETTING = 'telephony.sip';

    public function settings(): array
    {
        $saved = (array) SiteSetting::get(self::SETTING, []);

        return [
            'ws_url'          => (string) ($saved['ws_url'] ?? config('services.asterisk.ws_url')),
            'host'            => (string) ($saved['host'] ?? config('services.asterisk.host')),
            'port'            => (int) ($saved['port'] ?? config('services.asterisk.port', 5060)),
            'stun'            => array_values((array) ($saved['stun'] ?? array_filter([config('services.asterisk.stun')]))),
            'turn_url'        => (string) ($saved['turn_url'] ?? ''),
            'turn_username'   => (string) ($saved['turn_username'] ?? ''),
            'turn_credential' => (string) ($saved['turn_credential'] ?? ''),
        ];
    }

    public function save(array $settings): void
    {
        SiteSetting::set(self::SETTING, [
            'ws_url'          => trim((string) $settings['ws_url']),
            'host'            => trim((string) $settings['host']),
            'port'            => (int) $settings['port'],
            'stun'            => array_values(array_filter(array_map('trim', (array) $settings['stun']))),
            'turn_url'        => trim((string) ($settings['turn_url'] ?? '')),
            'turn_username'   => trim((string) ($settings['turn_username'] ?? '')),
            'turn_credential' => (string) ($settings['turn_credential'] ?? ''),
        ]);
    }

    public function wsUrl(): string
    {
        return $this->settings()['ws_url'];
    }

    /** The SIP domain browsers put in their URIs: the WebSocket's host. */
    public function domain(): string
    {
        $host = parse_url(preg_replace('#^ws#i', 'http', $this->wsUrl()), PHP_URL_HOST);

        return $host ?: $this->settings()['host'];
    }

    /** RTCPeerConnection iceServers, as a browser wants them. */
    public function iceServers(): array
    {
        $s = $this->settings();
        $servers = [];
        if ($s['stun']) {
            $servers[] = ['urls' => $s['stun']];
        }
        if ($s['turn_url'] !== '') {
            $servers[] = array_filter([
                'urls'       => array_map('trim', explode(',', $s['turn_url'])),
                'username'   => $s['turn_username'] ?: null,
                'credential' => $s['turn_credential'] ?: null,
            ]);
        }

        return $servers;
    }
}
