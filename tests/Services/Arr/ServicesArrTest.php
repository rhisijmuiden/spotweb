<?php

use PHPUnit\Framework\TestCase;

class ServicesArrTest extends TestCase
{
    private function settings()
    {
        $settings = $this->createMock(Services_Settings_Container::class);
        $settings->method('get')->willReturnMap([
            ['xsrfsecret', 'secret-one'],
            ['pass_salt', 'salt-one'],
        ]);

        return $settings;
    }

    public function testKindOfUsesCategoryAndSubcategory()
    {
        $this->assertSame('movie', Services_Arr_Title::kindOf(['category' => 0, 'subcatz' => 'z0|']));
        $this->assertSame('series', Services_Arr_Title::kindOf(['category' => 0, 'subcatz' => 'z1|']));
        $this->assertNull(Services_Arr_Title::kindOf(['category' => 1, 'subcatz' => 'z0|']));
    }

    public function testKeyIgnoresArticlesAccentsAndPunctuation()
    {
        $this->assertSame(Services_Arr_Title::key('The Bear'), Services_Arr_Title::key('Bear'));
        $this->assertSame(Services_Arr_Title::key('Amélie'), Services_Arr_Title::key('Amelie'));
        $this->assertSame(Services_Arr_Title::key('Fast & Furious'), Services_Arr_Title::key('Fast and Furious'));
        $this->assertSame(Services_Arr_Title::key('Wie is de Mol?'), Services_Arr_Title::key('wie is de mol'));
    }

    public function testParseMovieWithYearInBrackets()
    {
        $p = Services_Arr_Title::parse('Blade Runner 2049 (2017) 4K');
        $this->assertSame('Blade Runner 2049', $p['title']);
        $this->assertSame(2017, (int) $p['year']);
    }

    public function testParseMovieWithDotsAndAudio()
    {
        $p = Services_Arr_Title::parse('Oppenheimer.2023.1080p.WEB-DL.DD5.1');
        $this->assertSame('Oppenheimer', $p['title']);
        $this->assertSame(2023, (int) $p['year']);
    }

    public function testParseSeriesMarkers()
    {
        $p = Services_Arr_Title::parse('The Bear S03E04 1080p');
        $this->assertSame('The Bear', $p['title']);
        $this->assertSame(3, (int) $p['season']);
        $this->assertSame(4, (int) $p['episode']);

        $p = Services_Arr_Title::parse('Wie is de Mol? Seizoen 25 NL');
        $this->assertSame(25, (int) $p['season']);
        $this->assertSame(Services_Arr_Title::key('Wie is de Mol'), $p['key']);
    }

    public function testDownloadNameIsCleanAndUsesLibraryTitle()
    {
        $p = Services_Arr_Title::parse('Wie is de Mol? Seizoen 25 NL');
        $this->assertSame('Wie is de Mol S25 NL', Services_Arr_Title::downloadName($p, 'series'));

        $p = Services_Arr_Title::parse('flodder 1986 remastered');
        $name = Services_Arr_Title::downloadName($p, 'movie', 'Flodder', 1986);
        $this->assertStringStartsWith('Flodder (1986)', $name);
        $this->assertSame(0, preg_match('#[\\\\/:*?"<>|]#', $name));
    }

    public function testRowsForRadarrMovie()
    {
        $rows = Services_Arr_Library::rowsFor('radarr', [
            'id' => 7, 'title' => 'Dune: Part Two', 'year' => 2024, 'imdbId' => 'tt15239678',
            'hasFile' => true, 'monitored' => true, 'titleSlug' => 'dune-part-two-693134',
            'alternateTitles' => [['title' => 'Dune 2']],
        ]);
        $keys = array_column($rows, 'titlekey');
        $this->assertContains(Services_Arr_Title::key('Dune Part Two'), $keys);
        $this->assertContains(Services_Arr_Title::key('Dune 2'), $keys);
        $this->assertSame('ondisk', $rows[0]['status']);
        $this->assertSame('tt15239678', $rows[0]['imdbid']);
    }

    public function testRowsForSonarrSeriesCountsMissingAndSeasons()
    {
        $rows = Services_Arr_Library::rowsFor('sonarr', [
            'id' => 3, 'title' => 'The Bear', 'year' => 2022, 'monitored' => true,
            'statistics' => ['episodeCount' => 28, 'episodeFileCount' => 25],
            'seasons' => [
                ['seasonNumber' => 2, 'monitored' => true, 'statistics' => ['episodeFileCount' => 10, 'episodeCount' => 10, 'totalEpisodeCount' => 10]],
                ['seasonNumber' => 3, 'monitored' => true, 'statistics' => ['episodeFileCount' => 7, 'episodeCount' => 10, 'totalEpisodeCount' => 10]],
            ],
        ]);
        $this->assertSame('missing', $rows[0]['status']);
        $this->assertSame(3, $rows[0]['missing']);
        $detail = json_decode($rows[0]['detail'], true);
        $this->assertSame([7, 10, 10, 1], $detail[3]);
    }

    public function testPickPrefersImdbThenYear()
    {
        $key = Services_Arr_Title::key('Dune');
        $old = ['arrid' => 1, 'year' => 1984, 'status' => 'ondisk'];
        $new = ['arrid' => 2, 'year' => 2021, 'status' => 'missing'];
        $rows = ['keys' => ['radarr' => [$key => [$old, $new]]], 'imdb' => ['radarr' => ['tt1160419' => [$new]]]];

        $this->assertSame($new, Services_Arr_Library::pick($rows, 'radarr', ['key' => $key, 'year' => 0], 'tt1160419'));
        $this->assertSame($old, Services_Arr_Library::pick($rows, 'radarr', ['key' => $key, 'year' => 1984]));
        $this->assertSame('notin', Services_Arr_Library::pick($rows, 'radarr', ['key' => $key, 'year' => 2000]));
        $this->assertNull(Services_Arr_Library::pick($rows, 'radarr', ['key' => $key, 'year' => 0]));
        $this->assertSame('notin', Services_Arr_Library::pick($rows, 'radarr', ['key' => 'other', 'year' => 0]));
    }

    public function testSureMatchNeedsTitleAndYear()
    {
        $candidates = [
            ['title' => 'Flodder', 'year' => 1986, 'tmdbId' => 1],
            ['title' => 'Flodder in Amerika!', 'year' => 1992, 'tmdbId' => 2],
        ];
        $d = ['parsed' => Services_Arr_Title::parse('Flodder 1986 Remastered')];
        $this->assertSame(1, Services_Arr_Integration::sureMatch($candidates, $d, '')['tmdbId']);

        $d = ['parsed' => Services_Arr_Title::parse('Flodder 2005')];
        $this->assertNull(Services_Arr_Integration::sureMatch($candidates, $d, ''));

        $candidates[] = ['title' => 'Something else', 'year' => 2001, 'imdbId' => 'tt0000001'];
        $this->assertSame('Something else', Services_Arr_Integration::sureMatch($candidates, $d, 'tt0000001')['title']);
    }

    public function testMonitorSeasonsKeepsOnlyCurrentAndSpotSeason()
    {
        $seasons = [['seasonNumber' => 0], ['seasonNumber' => 1], ['seasonNumber' => 2], ['seasonNumber' => 3]];
        $result = array_column(Services_Arr_Integration::monitorSeasons($seasons, 1), 'monitored', 'seasonNumber');
        $this->assertSame([0 => false, 1 => true, 2 => false, 3 => true], $result);

        $result = array_column(Services_Arr_Integration::monitorSeasons($seasons, null), 'monitored', 'seasonNumber');
        $this->assertSame([0 => false, 1 => false, 2 => false, 3 => true], $result);
    }

    public function testApiKeyIsEncryptedAndCanBeRead()
    {
        $settings = $this->settings();
        $stored = Services_Arr_Config::encrypt($settings, 'abcdef0123456789');
        $this->assertStringStartsWith('enc1:', $stored);
        $this->assertStringNotContainsString('abcdef', $stored);
        $this->assertSame('abcdef0123456789', Services_Arr_Config::decrypt($settings, $stored));
        $this->assertStringEndsWith('6789', Services_Arr_Config::maskedKey($settings, $stored));
        $this->assertSame('', Services_Arr_Config::decrypt($settings, 'enc1:broken'));
    }

    public function testFromFormKeepsSavedKeyAndValidates()
    {
        $settings = $this->settings();
        $current = Services_Arr_Config::defaults();
        $current['radarr']['apikey'] = Services_Arr_Config::encrypt($settings, 'saved-key');

        $errors = [];
        $result = Services_Arr_Config::fromForm($settings, [
            'radarr' => ['enabled' => '1', 'url' => 'http://10.0.0.2:7878/', 'apikey' => ''],
            'sonarr' => ['enabled' => '1', 'url' => 'not a url', 'apikey' => 'x'],
        ], $current, $errors);

        $this->assertSame('http://10.0.0.2:7878', $result['radarr']['url']);
        $this->assertSame('saved-key', Services_Arr_Config::decrypt($settings, $result['radarr']['apikey']));
        $this->assertCount(1, $errors);

        $errors = [];
        $result = Services_Arr_Config::fromForm($settings, ['radarr' => ['url' => 'http://10.0.0.2:7878', 'clearkey' => '1']], $current, $errors);
        $this->assertSame('', $result['radarr']['apikey']);
        $this->assertSame([], $errors);
    }

    public function testUrlValidation()
    {
        $this->assertTrue((bool) Services_Arr_Client::isValidUrl('192.168.1.20:7878'));
        $this->assertTrue((bool) Services_Arr_Client::isValidUrl('https://radarr.example.org/radarr'));
        $this->assertTrue((bool) Services_Arr_Client::isValidUrl('http://[::1]:7878'));
        $this->assertFalse((bool) Services_Arr_Client::isValidUrl('not a url'));
        $this->assertFalse((bool) Services_Arr_Client::isValidUrl('ftp://10.0.0.2'));
    }

    public function testClientTurnsHttpErrorsIntoReadableMessages()
    {
        Services_Arr_Client::setTransport(function () { return [401, '{"error":"Unauthorized"}', '']; });
        try {
            (new Services_Arr_Client('radarr', 'http://10.0.0.2:7878', 'bad'))->systemStatus();
            $this->fail('expected an exception');
        } catch (Services_Arr_Exception $e) {
            $this->assertStringContainsString('API key', $e->getMessage());
        } finally {
            Services_Arr_Client::setTransport(null);
        } // finally
    }
}
