<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * In production every request reaches this app through Caddy (TLS edge) and
     * then HAProxy, both on the private Docker network — the container's :8080
     * is never publicly reachable. Without trusting them, Laravel sees scheme
     * `http` (so it generates http:// URLs and redirects) and treats the proxy's
     * container IP as the client IP (collapsing rate limiting into one bucket).
     *
     * Scoped to RFC-1918 ranges rather than '*' so a spoofed X-Forwarded-For
     * from a genuinely public client can never be honoured.
     *
     * @var array<int, string>|string|null
     */
    protected $proxies = [
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '127.0.0.1',
    ];

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;
}
