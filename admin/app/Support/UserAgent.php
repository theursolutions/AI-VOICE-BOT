<?php

namespace App\Support;

/**
 * Minimal User-Agent parser — browser, OS, device class, bot flag.
 *
 * Hand-rolled rather than pulling in a UA-parsing package: we need four
 * coarse facts for an internal analytics table, not a regex database with
 * monthly updates. Order matters throughout — Edge advertises itself as
 * Chrome, Chrome advertises itself as Safari, and every one of them says
 * "Mozilla/5.0" — so the more specific token is always tested first.
 */
final class UserAgent
{
    /** Substrings that mean "not a person". Lowercased comparison. */
    private const BOT_TOKENS = [
        'bot', 'crawl', 'spider', 'slurp', 'curl', 'wget', 'python-requests',
        'httpclient', 'okhttp', 'axios', 'headlesschrome', 'phantomjs',
        'lighthouse', 'pagespeed', 'monitoring', 'uptime', 'preview',
        'facebookexternalhit', 'whatsapp', 'telegrambot', 'discordbot',
        'slackbot', 'twitterbot', 'linkedinbot', 'embedly', 'go-http-client',
        'postman', 'insomnia', 'scrapy', 'ahrefs', 'semrush', 'mj12', 'dotbot',
    ];

    /** [needle, label] — first match wins, so specific before generic. */
    private const BROWSERS = [
        ['edg/',        'Edge'],
        ['edga/',       'Edge'],
        ['opr/',        'Opera'],
        ['opera',       'Opera'],
        ['samsungbrowser', 'Samsung Internet'],
        ['vivaldi',     'Vivaldi'],
        ['brave',       'Brave'],
        ['yabrowser',   'Yandex'],
        ['firefox/',    'Firefox'],
        ['fxios/',      'Firefox'],
        ['crios/',      'Chrome'],
        ['chrome/',     'Chrome'],
        ['safari/',     'Safari'],
        ['msie',        'Internet Explorer'],
        ['trident/',    'Internet Explorer'],
    ];

    /**
     * @return array{browser:?string,browser_version:?string,os:?string,device_type:string,is_bot:bool}
     */
    public static function parse(?string $ua): array
    {
        $ua = trim((string) $ua);

        if ($ua === '') {
            return [
                'browser' => null, 'browser_version' => null, 'os' => null,
                // A request with no User-Agent at all is never a browser.
                'device_type' => 'bot', 'is_bot' => true,
            ];
        }

        $low = strtolower($ua);

        foreach (self::BOT_TOKENS as $token) {
            if (str_contains($low, $token)) {
                return [
                    'browser'         => self::botName($ua),
                    'browser_version' => null,
                    'os'              => null,
                    'device_type'     => 'bot',
                    'is_bot'          => true,
                ];
            }
        }

        return [
            'browser'         => self::browser($low),
            'browser_version' => self::browserVersion($low),
            'os'              => self::os($low),
            'device_type'     => self::deviceType($low),
            'is_bot'          => false,
        ];
    }

    private static function browser(string $low): ?string
    {
        foreach (self::BROWSERS as [$needle, $label]) {
            if (str_contains($low, $needle)) {
                return $label;
            }
        }

        return null;
    }

    private static function browserVersion(string $low): ?string
    {
        // Match the version that belongs to the token we identified above,
        // not the first "x/1.2.3" in the string — Chrome UAs carry four.
        foreach (self::BROWSERS as [$needle, $label]) {
            if (! str_contains($low, $needle)) {
                continue;
            }

            // Safari's "Safari/605.1.15" is the WebKit build, not the browser
            // release; the real version is in the preceding "Version/17.4".
            if ($label === 'Safari' && preg_match('#\bversion/([0-9]+(?:\.[0-9]+)?)#', $low, $m)) {
                return $m[1];
            }

            $token = rtrim($needle, '/');
            if (preg_match('#' . preg_quote($token, '#') . '[/ ]([0-9]+(?:\.[0-9]+)?)#', $low, $m)) {
                return $m[1];
            }
            // MSIE writes "MSIE 9.0"; Trident carries no product version.
            if (preg_match('#\brv:([0-9]+(?:\.[0-9]+)?)#', $low, $m)) {
                return $m[1];
            }

            return null;
        }

        return null;
    }

    private static function os(string $low): ?string
    {
        // iPadOS still says "like Mac OS X", so iOS/iPad must precede macOS.
        return match (true) {
            str_contains($low, 'windows nt 10')  => 'Windows 10/11',
            str_contains($low, 'windows nt 6.3') => 'Windows 8.1',
            str_contains($low, 'windows nt 6.1') => 'Windows 7',
            str_contains($low, 'windows')        => 'Windows',
            str_contains($low, 'android')        => 'Android',
            str_contains($low, 'iphone')         => 'iOS',
            str_contains($low, 'ipad')           => 'iPadOS',
            str_contains($low, 'mac os x')       => 'macOS',
            str_contains($low, 'cros')           => 'ChromeOS',
            str_contains($low, 'ubuntu')         => 'Ubuntu',
            str_contains($low, 'linux')          => 'Linux',
            default                              => null,
        };
    }

    private static function deviceType(string $low): string
    {
        if (str_contains($low, 'ipad') || str_contains($low, 'tablet')) {
            return 'tablet';
        }
        // "Mobile" alone is the reliable signal; Android tablets omit it.
        if (str_contains($low, 'mobi') || str_contains($low, 'iphone') || str_contains($low, 'android')) {
            return str_contains($low, 'mobi') ? 'mobile' : 'tablet';
        }

        return 'desktop';
    }

    /** Pull a readable bot name out of the UA when one is announced. */
    private static function botName(string $ua): string
    {
        if (preg_match('#([A-Za-z][A-Za-z0-9 _.\-]{1,30}(?:bot|Bot|crawler|Crawler|spider|Spider))#', $ua, $m)) {
            return trim($m[1]);
        }

        return 'Bot / script';
    }

    /**
     * Best-guess language tag from an Accept-Language header.
     * "en-GB,en;q=0.9,ur;q=0.8" → "en-GB".
     */
    public static function language(?string $acceptLanguage): ?string
    {
        $first = trim(explode(',', (string) $acceptLanguage)[0] ?? '');
        $first = trim(explode(';', $first)[0] ?? '');

        // Guard the column width and reject junk headers.
        if ($first === '' || strlen($first) > 20 || ! preg_match('#^[A-Za-z]{2,8}(-[A-Za-z0-9]{2,8})*$#', $first)) {
            return null;
        }

        return $first;
    }
}
