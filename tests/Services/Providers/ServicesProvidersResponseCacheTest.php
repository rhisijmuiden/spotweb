<?php

use PHPUnit\Framework\TestCase;

class ServicesProvidersResponseCacheTest extends TestCase
{
    private $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/spotweb-response-cache-'.getmypid().'-'.mt_rand();
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->dir.'/responses/*') as $file) {
            @unlink($file);
        }
        @rmdir($this->dir.'/responses');
        @rmdir($this->dir);
    }

    public function testRememberBuildsOnceAndThenServesFromCache()
    {
        $cache = new Services_Providers_ResponseCache($this->dir, 600);
        $key = $cache->makeKey(['api', 3, ['t' => 'search']]);
        $calls = 0;
        $producer = function () use (&$calls) {
            $calls++;

            return ['headers' => ['Content-Type: text/xml; charset=utf-8'], 'body' => "<rss>\n<item/></rss>"];
        };

        $first = $cache->remember($key, $producer);
        $second = $cache->remember($key, $producer);

        $this->assertSame(1, $calls);
        $this->assertSame('MISS', $first['cache']);
        $this->assertSame('HIT', $second['cache']);
        $this->assertSame("<rss>\n<item/></rss>", $second['body']);
        $this->assertSame(['Content-Type: text/xml; charset=utf-8'], $second['headers']);
    }

    public function testErrorsAreNotCached()
    {
        $cache = new Services_Providers_ResponseCache($this->dir, 600);
        $key = $cache->makeKey(['error']);
        $calls = 0;
        $producer = function () use (&$calls) {
            $calls++;

            return null;
        };

        $this->assertNull($cache->remember($key, $producer));
        $this->assertNull($cache->remember($key, $producer));
        $this->assertSame(2, $calls);
    }

    public function testDifferentKeysDoNotShareResponses()
    {
        $cache = new Services_Providers_ResponseCache($this->dir, 600);
        $cache->put($cache->makeKey(['api', 3, 100]), [], 'user 3');

        $this->assertNull($cache->get($cache->makeKey(['api', 4, 100])));
        $this->assertNull($cache->get($cache->makeKey(['api', 3, 101])), 'a new spot must give a fresh answer');
        $this->assertSame('user 3', $cache->get($cache->makeKey(['api', 3, 100]))['body']);
    }

    public function testExpiredResponsesAreIgnoredAndPurged()
    {
        $cache = new Services_Providers_ResponseCache($this->dir, 60);
        $key = $cache->makeKey(['old']);
        $cache->put($key, [], 'old body');
        foreach (glob($this->dir.'/responses/*') as $file) {
            touch($file, time() - 600);
        }

        $this->assertNull($cache->get($key));
        $cache->purgeExpired();
        $this->assertSame([], glob($this->dir.'/responses/*'));
    }

    public function testZeroTtlSwitchesTheCacheOff()
    {
        $cache = new Services_Providers_ResponseCache($this->dir, 0);
        $calls = 0;
        $producer = function () use (&$calls) {
            $calls++;

            return ['headers' => [], 'body' => 'x'];
        };

        $this->assertSame('OFF', $cache->remember('abc', $producer)['cache']);
        $cache->remember('abc', $producer);
        $this->assertSame(2, $calls);
        $this->assertFalse(is_dir($this->dir.'/responses'));
    }
}
