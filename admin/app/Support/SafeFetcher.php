<?php

namespace App\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;
use Psr\Http\Message\ResponseInterface;

/**
 * Fetch a URL someone else controls without letting it reach our network.
 *
 * Built for Lead Generation enrichment (reading a prospect's own contact
 * page), where the URL comes from third-party data. Guards, all enforced on
 * EVERY redirect hop, since a public URL redirecting to 169.254.169.254 is
 * the classic bypass:
 *
 *   - http/https only, default ports only, no credentials in the URL
 *   - the host's every A/AAAA record must be public; one private address
 *     fails the whole host (an attacker controls which record we'd get)
 *   - the connection is pinned to the address we checked (CURLOPT_RESOLVE),
 *     so DNS can't answer differently between our check and curl's lookup
 *   - robots.txt is honoured for our user agent
 *   - size and time caps; redirects followed by hand, at most N
 *
 * Returns a result object instead of throwing for anything the remote site
 * did — callers record "blocked"/"failed" on the prospect and move on.
 */
class SafeFetcher
{
    /** @var callable(string): array<string> host => list of IPs */
    private $resolver;

    public function __construct(?Client $http = null, ?callable $resolver = null)
    {
        $this->http     = $http ?? new Client(['http_errors' => false]);
        $this->resolver = $resolver ?? [self::class, 'resolveHost'];
    }

    private Client $http;

    public function get(string $url): SafeFetchResult
    {
        $cfg = (array) config('leadgen.fetch', []);
        $max = (int) ($cfg['max_redirects'] ?? 5);

        for ($hop = 0; $hop <= $max; $hop++) {
            $target = $this->vet($url);
            if (is_string($target)) {
                return SafeFetchResult::blocked($url, $target);
            }

            if (($cfg['respect_robots'] ?? true) && !$this->robotsAllow($target)) {
                return SafeFetchResult::blocked($url, 'robots_disallowed');
            }

            try {
                $response = $this->request($target, $cfg);
            } catch (GuzzleException $e) {
                return SafeFetchResult::failed($url, 'request_failed: ' . $e->getMessage());
            }

            $status = $response->getStatusCode();
            if ($status >= 300 && $status < 400 && $response->hasHeader('Location')) {
                $url = self::absolutise($url, $response->getHeaderLine('Location'));
                continue;
            }

            $body = $this->readCapped($response, (int) ($cfg['max_bytes'] ?? 5242880));
            if ($body === null) {
                return SafeFetchResult::blocked($url, 'too_large');
            }
            return new SafeFetchResult($url, $status, $response->getHeaderLine('Content-Type'), $body, null);
        }

        return SafeFetchResult::blocked($url, 'too_many_redirects');
    }

    /**
     * Check a URL and resolve it. Returns ['url','host','port','ip'] when it
     * is safe to connect to, or a reason string when it isn't.
     */
    public function vet(string $url): array|string
    {
        $parts = parse_url($url);
        if (!$parts || empty($parts['scheme'])) {
            return 'invalid_url';
        }
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return 'scheme_not_allowed';
        }
        if (empty($parts['host'])) {
            return 'invalid_url';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'credentials_in_url';
        }
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (!in_array($port, [80, 443], true)) {
            return 'port_not_allowed';
        }

        $host = strtolower(trim($parts['host'], '[]'));
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal') || str_ends_with($host, '.local')) {
            return 'private_host';
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (array) ($this->resolver)($host);
        if (!$ips) {
            return 'dns_failed';
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return 'private_address';
            }
        }

        return ['url' => $url, 'host' => $host, 'port' => $port, 'ip' => $ips[0], 'scheme' => $scheme];
    }

    /** True only for globally routable unicast addresses. */
    public static function isPublicIp(string $ip): bool
    {
        // IPv4-mapped IPv6 (::ffff:10.0.0.1) — judge the embedded v4 address.
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) {
            $ip = $m[1];
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        // Ranges PHP's flags don't cover.
        foreach (['100.64.0.0/10', '192.0.0.0/24', '198.18.0.0/15', '224.0.0.0/4', '0.0.0.0/8', 'fe80::/10', 'fc00::/7', 'ff00::/8', '64:ff9b::/96'] as $cidr) {
            if (self::inCidr($ip, $cidr)) {
                return false;
            }
        }
        return true;
    }

    public static function inCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $ipBin  = @inet_pton($ip);
        $netBin = @inet_pton($subnet);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        $bits  = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = chr((0xFF << (8 - $rem)) & 0xFF);
        return (($ipBin[$bytes] & $mask) === ($netBin[$bytes] & $mask));
    }

    /** All A and AAAA records for a host. */
    public static function resolveHost(string $host): array
    {
        $ips = [];
        foreach ([DNS_A, DNS_AAAA] as $type) {
            foreach ((array) @dns_get_record($host, $type) as $r) {
                $ips[] = $r['ip'] ?? $r['ipv6'] ?? null;
            }
        }
        return array_values(array_filter(array_unique($ips)));
    }

    /** Is our user agent allowed to fetch this path? Unknown/unreadable robots.txt = allowed. */
    public function robotsAllow(array $target): bool
    {
        $origin = $target['scheme'] . '://' . $target['host'] . (in_array($target['port'], [80, 443], true) ? '' : ':' . $target['port']);
        $ttl    = (int) config('leadgen.fetch.robots_cache_ttl', 3600);

        $rules = Cache::remember('leadgen:robots:' . sha1($origin), $ttl, function () use ($origin) {
            $robots = $this->vet($origin . '/robots.txt');
            if (is_string($robots)) {
                return [];
            }
            try {
                $res = $this->request($robots, (array) config('leadgen.fetch', []));
            } catch (GuzzleException) {
                return [];
            }
            if ($res->getStatusCode() !== 200) {
                return [];
            }
            return self::parseRobots((string) $this->readCapped($res, 512 * 1024), (string) config('leadgen.fetch.user_agent'));
        });

        $path = (parse_url($target['url'], PHP_URL_PATH) ?: '/') . (($q = parse_url($target['url'], PHP_URL_QUERY)) ? '?' . $q : '');
        return self::robotsDecide($rules, $path);
    }

    /**
     * Rules from the most specific group that names us, else the `*` group.
     * Returns [['allow'|'disallow', prefix], ...].
     */
    public static function parseRobots(string $txt, string $userAgent): array
    {
        $token  = strtolower(strtok($userAgent, '/') ?: $userAgent);
        $groups = [];
        $agents = [];
        $inRules = false;

        foreach (preg_split('/\R/', $txt) as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line));
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$k, $v] = array_map('trim', explode(':', $line, 2));
            $k = strtolower($k);
            if ($k === 'user-agent') {
                if ($inRules) {
                    $agents = [];
                    $inRules = false;
                }
                $agents[] = strtolower($v);
                continue;
            }
            if ($k === 'allow' || $k === 'disallow') {
                $inRules = true;
                foreach ($agents as $a) {
                    $groups[$a][] = [$k, $v];
                }
            }
        }

        foreach ($groups as $agent => $rules) {
            if ($agent !== '*' && str_contains($token, $agent)) {
                return $rules;
            }
        }
        return $groups['*'] ?? [];
    }

    /** Longest matching rule wins; Allow wins a tie. Empty Disallow allows everything. */
    public static function robotsDecide(array $rules, string $path): bool
    {
        $best = null;
        $bestLen = -1;
        foreach ($rules as [$kind, $prefix]) {
            if ($prefix === '') {
                continue;
            }
            $regex = '#^' . str_replace(['\*', '\$'], ['.*', '$'], preg_quote($prefix, '#')) . '#';
            if (preg_match($regex, $path)) {
                $len = strlen($prefix);
                if ($len > $bestLen || ($len === $bestLen && $kind === 'allow')) {
                    $best = $kind;
                    $bestLen = $len;
                }
            }
        }
        return $best !== 'disallow';
    }

    public static function absolutise(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $b = parse_url($base);
        $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($location, '//')) {
            return $b['scheme'] . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $dir = isset($b['path']) ? preg_replace('#/[^/]*$#', '/', $b['path']) : '/';
        return $origin . $dir . $location;
    }

    private function request(array $target, array $cfg): ResponseInterface
    {
        return $this->http->request('GET', $target['url'], [
            'allow_redirects' => false,
            'http_errors'     => false,
            'stream'          => true,
            'timeout'         => (int) ($cfg['timeout'] ?? 10),
            'connect_timeout' => (int) ($cfg['connect_timeout'] ?? 5),
            'headers'         => [
                'User-Agent' => (string) ($cfg['user_agent'] ?? 'serveAI-LeadGen/1.0'),
                'Accept'     => 'text/html,application/xhtml+xml,text/plain;q=0.8,*/*;q=0.5',
            ],
            // Pin the connection to the address vet() approved.
            'curl' => [
                CURLOPT_RESOLVE   => [$target['host'] . ':' . $target['port'] . ':' . (str_contains($target['ip'], ':') ? '[' . $target['ip'] . ']' : $target['ip'])],
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            ],
        ]);
    }

    /** Body, or null when it exceeds $max bytes. */
    private function readCapped(ResponseInterface $response, int $max): ?string
    {
        $declared = (int) $response->getHeaderLine('Content-Length');
        if ($declared > $max) {
            return null;
        }
        $stream = $response->getBody();
        $buf = '';
        while (!$stream->eof()) {
            $buf .= $stream->read(65536);
            if (strlen($buf) > $max) {
                return null;
            }
        }
        return $buf;
    }
}
