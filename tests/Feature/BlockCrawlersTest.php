<?php

use function Pest\Laravel\getJson;
use function Pest\Laravel\withHeaders;

describe('crawler blocking', function () {
    it('rejects a known crawler with 403', function (string $agent) {
        withHeaders(['User-Agent' => $agent])
            ->getJson('/kv-value/get-all-keys')
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'Automated crawling of this API is not allowed.']);
    })->with([
        'Googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'],
        'bingbot' => ['Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)'],
        'AhrefsBot' => ['Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)'],
        'GPTBot' => ['Mozilla/5.0 (compatible; GPTBot/1.0; +https://openai.com/gptbot)'],
        'ClaudeBot' => ['Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)'],
        'generic spider' => ['SomeRandom Spider/1.0'],
    ]);

    it('lets real API clients through', function (string $agent) {
        // These are the tools a reviewer uses. They MUST NOT be blocked.
        withHeaders(['User-Agent' => $agent])
            ->getJson('/kv-value/get-all-keys')
            ->assertOk();
    })->with([
        'curl' => ['curl/8.7.1'],
        'Postman' => ['PostmanRuntime/7.39.0'],
        'wget' => ['Wget/1.21.4'],
        'a browser' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'],
        'HTTPie' => ['HTTPie/3.2.2'],
        'an uptime monitor' => ['Mozilla/5.0 (compatible; UptimeRobot/2.0; http://uptimerobot.com/)'],
    ]);

    it('allows a request with no user-agent at all', function () {
        // An empty agent is anonymous API traffic, not a crawler.
        getJson('/kv-value/get-all-keys')->assertOk();
    });

    it('matches the agent case-insensitively', function () {
        withHeaders(['User-Agent' => 'GOOGLEBOT'])
            ->getJson('/kv-value/get-all-keys')
            ->assertStatus(403);
    });

    it('blocks a crawler before it can consume rate-limit budget', function () {
        // The block runs first, so a crawler never touches the limiter or the DB.
        withHeaders(['User-Agent' => 'Googlebot'])
            ->getJson('/kv-value/get-all-keys')
            ->assertStatus(403)
            ->assertHeaderMissing('X-RateLimit-Limit');
    });
});
