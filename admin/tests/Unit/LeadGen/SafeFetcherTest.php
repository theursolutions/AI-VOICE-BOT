<?php

namespace Tests\Unit\LeadGen;

use App\Support\SafeFetcher;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The guard in front of every prospect-website fetch. The URLs come from
 * third-party data, so every way of reaching our own network is a test.
 */
class SafeFetcherTest extends TestCase
{
    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['leadgen.fetch.respect_robots' => false]);
    }

    /** @param array<string, array<string>> $dns host => IPs */
    private function fetcher(array $responses, array $dns = []): SafeFetcher
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $resolver = fn (string $host) => $dns[$host] ?? ['93.184.216.34'];
        return new SafeFetcher(new Client(['handler' => $stack]), $resolver);
    }

    public function test_private_and_special_addresses_are_not_public(): void
    {
        foreach (['127.0.0.1', '10.1.2.3', '172.16.0.9', '192.168.1.1', '169.254.169.254', '100.64.0.1',
                  '0.0.0.0', '::1', 'fe80::1', 'fd00::1', '::ffff:10.0.0.1', '224.0.0.1', '198.18.0.1'] as $ip) {
            $this->assertFalse(SafeFetcher::isPublicIp($ip), $ip);
        }
        foreach (['93.184.216.34', '8.8.8.8', '2606:4700:4700::1111'] as $ip) {
            $this->assertTrue(SafeFetcher::isPublicIp($ip), $ip);
        }
    }

    public function test_vet_rejects_bad_urls_before_any_request(): void
    {
        $f = $this->fetcher([], ['internal.example' => ['10.0.0.5'], 'mixed.example' => ['93.184.216.34', '127.0.0.1']]);

        $this->assertSame('scheme_not_allowed', $f->vet('file:///etc/passwd'));
        $this->assertSame('scheme_not_allowed', $f->vet('gopher://example.com/'));
        $this->assertSame('port_not_allowed', $f->vet('http://example.com:6379/'));
        $this->assertSame('credentials_in_url', $f->vet('http://user:pw@example.com/'));
        $this->assertSame('private_host', $f->vet('http://localhost/'));
        $this->assertSame('private_address', $f->vet('http://169.254.169.254/latest/meta-data/'));
        $this->assertSame('private_address', $f->vet('http://internal.example/'));
        // One private record poisons the host: we don't control which one curl would pick.
        $this->assertSame('private_address', $f->vet('https://mixed.example/'));
        $this->assertIsArray($f->vet('https://example.com/contact'));
    }

    public function test_redirect_to_a_private_address_is_blocked(): void
    {
        $f = $this->fetcher(
            [new Response(302, ['Location' => 'http://evil.example/admin'])],
            ['evil.example' => ['192.168.0.10']],
        );

        $res = $f->get('https://example.com/');

        $this->assertSame('private_address', $res->blockedReason);
        $this->assertCount(1, $this->history, 'the private hop must never be requested');
    }

    public function test_follows_safe_redirects_and_returns_the_body(): void
    {
        $f = $this->fetcher([
            new Response(301, ['Location' => '/contact']),
            new Response(200, ['Content-Type' => 'text/html'], '<a href="mailto:info@acme.ae">mail</a>'),
        ]);

        $res = $f->get('https://acme.ae/');

        $this->assertTrue($res->ok());
        $this->assertTrue($res->isHtml());
        $this->assertSame('https://acme.ae/contact', $res->url);
        $this->assertStringContainsString('info@acme.ae', $res->body);
        // Every request is pinned to the vetted address.
        $this->assertSame(['acme.ae:443:93.184.216.34'], $this->history[1]['options']['curl'][CURLOPT_RESOLVE]);
    }

    public function test_redirect_loops_stop(): void
    {
        config(['leadgen.fetch.max_redirects' => 2]);
        $f = $this->fetcher(array_fill(0, 3, new Response(302, ['Location' => '/again'])));

        $this->assertSame('too_many_redirects', $f->get('https://acme.ae/')->blockedReason);
    }

    public function test_oversized_bodies_are_refused(): void
    {
        config(['leadgen.fetch.max_bytes' => 10]);
        $f = $this->fetcher([new Response(200, [], str_repeat('x', 50))]);

        $this->assertSame('too_large', $f->get('https://acme.ae/')->blockedReason);
    }

    public function test_robots_txt_is_honoured(): void
    {
        config(['leadgen.fetch.respect_robots' => true, 'leadgen.fetch.user_agent' => 'serveAI-LeadGen/1.0']);
        $f = $this->fetcher([
            new Response(200, [], "User-agent: *\nDisallow: /private\n"),
        ]);

        $this->assertSame('robots_disallowed', $f->get('https://acme.ae/private/team')->blockedReason);
    }

    public function test_robots_rules_pick_our_group_and_longest_match(): void
    {
        $txt = "User-agent: *\nDisallow: /\n\nUser-agent: serveAI-LeadGen\nDisallow: /admin\nAllow: /admin/contact\n";
        $rules = SafeFetcher::parseRobots($txt, 'serveAI-LeadGen/1.0 (+https://x/bot)');

        $this->assertTrue(SafeFetcher::robotsDecide($rules, '/contact'));
        $this->assertFalse(SafeFetcher::robotsDecide($rules, '/admin/users'));
        $this->assertTrue(SafeFetcher::robotsDecide($rules, '/admin/contact'));

        $generic = SafeFetcher::parseRobots($txt, 'OtherBot/2');
        $this->assertFalse(SafeFetcher::robotsDecide($generic, '/anything'));
        $this->assertTrue(SafeFetcher::robotsDecide(SafeFetcher::parseRobots("User-agent: *\nDisallow:\n", 'x'), '/'));
    }
}
