<?php

use PHPUnit\Framework\TestCase;

class ServicesStatsRequestsTest extends TestCase
{
    public function testChannelIsDerivedFromPageAndApiKey()
    {
        $this->assertSame('api', Services_Stats_Requests::channelFor('newznabapi', false));
        $this->assertSame('rss', Services_Stats_Requests::channelFor('rss', true));
        $this->assertSame('api', Services_Stats_Requests::channelFor('getnzb', true));
        $this->assertSame('web', Services_Stats_Requests::channelFor('getnzb', false));
        $this->assertSame('web', Services_Stats_Requests::channelFor('index', false));
    }

    public function testClientNameRecognisesCommonApps()
    {
        $this->assertSame('Prowlarr', Services_Stats_Requests::clientName('Prowlarr/1.24.3.4754 (ubuntu 24.04)'));
        $this->assertSame('Sonarr', Services_Stats_Requests::clientName('Sonarr/4.0.11'));
        $this->assertSame('SABnzbd', Services_Stats_Requests::clientName('SABnzbd/4.4.1'));
        $this->assertSame('Browser', Services_Stats_Requests::clientName('Mozilla/5.0 (X11; Linux x86_64) Firefox/140.0'));
        $this->assertSame('curl', Services_Stats_Requests::clientName('curl/8.5.0'));
        $this->assertSame('', Services_Stats_Requests::clientName(''));
    }
}
