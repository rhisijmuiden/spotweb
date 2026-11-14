<?php

/*
 * What the spot list, the spot page and a download need from Radarr and
 * Sonarr, for the current user.
 *
 *  - status():   is this spot's movie or series in the library? Answered
 *                from the local copy, never with an API call.
 *  - prepare():  the SABnzbd category and job name for a download.
 *  - ensure():   add the movie or series to Radarr / Sonarr when it is
 *                missing, just before the download goes to SABnzbd.
 */
class Services_Arr_Integration
{
    private $_settings;
    private $_daoFactory;
    private $_currentSession;
    private $_services;
    private $_config;
    private $_library = null;
    private $_refreshScheduled = false;

    /** @var array messageid => status, filled by statusForSpots() */
    private $_known = [];

    public function __construct(Services_Settings_Container $settings, Dao_Factory $daoFactory, array $currentSession)
    {
        $this->_settings = $settings;
        $this->_daoFactory = $daoFactory;
        $this->_currentSession = $currentSession;
        $this->_services = Services_Arr_Config::activeServices($settings, $currentSession);
        $this->_config = Services_Arr_Config::forPrefs($currentSession['user']['prefs'] ?? []);
    }

    public function isActive()
    {
        return !empty($this->_services);
    }

    public function hasService($service)
    {
        return isset($this->_services[$service]);
    }

    private function userId()
    {
        return (int) $this->_currentSession['user']['userid'];
    }

    public function library()
    {
        if ($this->_library === null) {
            $this->_library = new Services_Arr_Library($this->_daoFactory->getConnection());
        } // if

        return $this->_library;
    }

    /*
     * Movie or series, title parts and the service for one spot, or null
     */
    public function describe(array $spot)
    {
        $kind = Services_Arr_Title::kindOf($spot);
        $service = Services_Arr_Config::serviceForKind($kind);
        if ($service === null || !$this->hasService($service)) {
            return null;
        } // if

        return ['kind' => $kind, 'service' => $service, 'parsed' => Services_Arr_Title::parse($spot['title'] ?? '')];
    }

    public static function imdbIdOf(array $fullSpot)
    {
        foreach (['website', 'description'] as $field) {
            if (!empty($fullSpot[$field]) && preg_match('/imdb\.[a-z.]+\/(?:[a-z]{2}\/)?title\/(tt\d{5,10})/i', (string) $fullSpot[$field], $m)) {
                return strtolower($m[1]);
            } // if
        } // foreach

        return '';
    }

    // ------------------------------------------------------------------
    // status
    // ------------------------------------------------------------------

    /*
     * Status for a page of spots: one database query for all of them.
     * Returns messageid => status (see makeStatus()).
     */
    public function statusForSpots(array $spots)
    {
        if (!$this->isActive() || empty($spots)) {
            return [];
        } // if

        try {
            $described = [];
            $keys = [];
            foreach ($spots as $spot) {
                if (!isset($spot['messageid'])) {
                    continue;
                } // if
                $d = $this->describe($spot);
                if ($d !== null && $d['parsed']['key'] !== '') {
                    $described[$spot['messageid']] = $d;
                    $keys[] = $d['parsed']['key'];
                } // if
            } // foreach

            $result = [];
            if (!empty($described)) {
                $synced = $this->syncedServices();
                $rows = $this->library()->rowsForKeys($this->userId(), array_keys($this->_services), $keys);
                foreach ($described as $messageId => $d) {
                    $result[$messageId] = $this->makeStatus($d, $rows, '', $synced);
                } // foreach
            } // if

            $this->scheduleRefresh();
            $this->_known = $result + $this->_known;

            return $result;
        } catch (Exception $x) {
            // without the arrlibrary table (before upgrade-db) the list just shows no status
            return [];
        } // catch
    }

    /*
     * Status for one (full) spot; uses the IMDb link of the spot when there is one
     */
    public function statusForSpot(array $fullSpot)
    {
        $d = $this->isActive() ? $this->describe($fullSpot) : null;
        if ($d === null) {
            return null;
        } // if

        try {
            $imdb = self::imdbIdOf($fullSpot);
            $rows = $this->library()->rowsForKeys($this->userId(), [$d['service']], [$d['parsed']['key']], $imdb !== '' ? [$imdb] : []);
            $status = $this->makeStatus($d, $rows, $imdb, $this->syncedServices());
            $this->scheduleRefresh();
            if (isset($fullSpot['messageid'])) {
                $this->_known[$fullSpot['messageid']] = $status;
            } // if

            return $status;
        } catch (Exception $x) {
            return null;
        } // catch
    }

    public function knownStatus($messageId)
    {
        return $this->_known[$messageId] ?? null;
    }

    private function syncedServices()
    {
        $synced = [];
        foreach (array_keys($this->_services) as $service) {
            $row = $this->library()->getSyncRow($this->userId(), $service);
            $synced[$service] = ($row !== null && (int) $row['lastok'] > 0);
        } // foreach

        return $synced;
    }

    private function makeStatus(array $d, array $rows, $imdb, array $synced)
    {
        $status = [
            'service' => $d['service'],
            'kind'    => $d['kind'],
            'parsed'  => $d['parsed'],
            'imdbid'  => $imdb,
            'state'   => null,
            'item'    => null,
            'season'  => null,
        ];

        if (empty($synced[$d['service']])) {
            // we have never had a good copy of this library, so we cannot say "not in"
            return $status;
        } // if

        $pick = Services_Arr_Library::pick($rows, $d['service'], $d['parsed'], $imdb);
        if ($pick === Services_Arr_Library::STATE_NOTIN) {
            $status['state'] = Services_Arr_Library::STATE_NOTIN;
        } elseif (is_array($pick)) {
            $status['item'] = $pick;
            $status['state'] = $pick['status'];
            $season = $d['parsed']['season'];
            if ($d['service'] === Services_Arr_Client::SONARR && $season !== null && !empty($pick['detail'])) {
                $seasons = json_decode($pick['detail'], true);
                if (is_array($seasons) && isset($seasons[$season])) {
                    list($files, $episodes, $total, $monitored) = array_pad($seasons[$season], 4, 0);
                    $status['season'] = ['number' => (int) $season, 'files' => $files, 'episodes' => $episodes, 'total' => $total, 'monitored' => (bool) $monitored];
                } // if
            } // if
        } // elseif

        return $status;
    }

    /*
     * Label, colour and link for a status, for the templates
     */
    public function view($status)
    {
        if (!is_array($status) || $status['state'] === null) {
            return null;
        } // if

        $name = ($status['service'] === Services_Arr_Client::RADARR) ? 'Radarr' : 'Sonarr';
        $state = $status['state'];
        $tone = 'none';
        $item = $status['item'];
        $missing = (int) ($item['missing'] ?? 0);

        if ($state === Services_Arr_Library::STATE_NOTIN) {
            $label = sprintf(_('Not in %s'), $name);
            $long = sprintf(_('This title is not in your %s library yet. Downloading it adds it.'), $name);
        } elseif ($status['season'] !== null) {
            $s = $status['season'];
            $prefix = sprintf(_('S%d'), $s['number']);
            if ($s['episodes'] > 0 && $s['files'] >= $s['episodes']) {
                $tone = 'ok';
                $label = $name.' · '.sprintf(_('%s on disk'), $prefix);
                $long = sprintf(_('Season %d is complete in %s.'), $s['number'], $name);
            } elseif (!$s['monitored']) {
                $tone = 'muted';
                $label = $name.' · '.sprintf(_('%s not monitored'), $prefix);
                $long = sprintf(_('The series is in %s, but season %d is not monitored.'), $name, $s['number']);
            } else {
                $tone = 'want';
                $miss = max(1, $s['episodes'] - $s['files']);
                $label = $name.' · '.sprintf(ngettext('%s: %d missing', '%s: %d missing', $miss), $prefix, $miss);
                $long = sprintf(ngettext('%d episode of season %d is still missing in %s.', '%d episodes of season %d are still missing in %s.', $miss), $miss, $s['number'], $name);
            } // else
        } elseif ($state === Services_Arr_Library::STATE_ONDISK || $state === Services_Arr_Library::STATE_COMPLETE) {
            $tone = 'ok';
            $label = $name.' · '.(($state === Services_Arr_Library::STATE_ONDISK) ? _('on disk') : _('complete'));
            $long = sprintf(_('In %s and already downloaded.'), $name);
        } elseif ($state === Services_Arr_Library::STATE_MISSING) {
            $tone = 'want';
            $label = ($status['service'] === Services_Arr_Client::SONARR && $missing > 0)
                ? $name.' · '.sprintf(ngettext('%d missing', '%d missing', $missing), $missing)
                : $name.' · '._('missing');
            $long = sprintf(_('In %s and monitored, but not downloaded yet.'), $name);
        } else {
            $tone = 'muted';
            $label = $name.' · '._('not monitored');
            $long = sprintf(_('In %s, but not monitored.'), $name);
        } // else

        return [
            'service' => $status['service'],
            'name'    => $name,
            'state'   => $state,
            'tone'    => $tone,
            'label'   => $label,
            'long'    => $long,
            'title'   => $item['title'] ?? '',
            'year'    => (int) ($item['year'] ?? 0),
            'link'    => $item ? Services_Arr_Client::webLink($status['service'], $this->_services[$status['service']]['url'], (string) $item['slug']) : '',
        ];
    }

    // ------------------------------------------------------------------
    // downloads
    // ------------------------------------------------------------------

    /*
     * SABnzbd category and job name for a spot, or null to keep Spotweb's own.
     * Uses the library title when we know it, so Radarr / Sonarr recognise it.
     */
    public function prepare(array $spot, $status = null)
    {
        $d = $this->describe($spot);
        if ($d === null) {
            return null;
        } // if

        if ($status === null && isset($spot['messageid'])) {
            $status = $this->knownStatus($spot['messageid']);
        } // if
        $item = (is_array($status) && is_array($status['item'] ?? null)) ? $status['item'] : null;

        return [
            'category' => (string) $this->_services[$d['service']]['category'],
            'name'     => Services_Arr_Title::downloadName($d['parsed'], $d['kind'], $item ? $item['title'] : '', $item ? (int) $item['year'] : 0),
        ];
    }

    /*
     * Make sure the spot's movie / series is in Radarr / Sonarr. Adds it
     * when it is surely missing and we find a sure match. Never throws.
     *
     * Returns ['ok' => bool, 'added' => bool, 'message' => string, 'status' => status]
     */
    public function ensure(array $fullSpot)
    {
        $d = $this->describe($fullSpot);
        if ($d === null) {
            return ['ok' => true, 'added' => false, 'message' => '', 'status' => null];
        } // if

        $name = ($d['service'] === Services_Arr_Client::RADARR) ? 'Radarr' : 'Sonarr';
        $status = $this->statusForSpot($fullSpot);
        if (is_array($status) && is_array($status['item'])) {
            return ['ok' => true, 'added' => false, 'message' => sprintf(_('%s is already in %s.'), $status['item']['title'], $name), 'status' => $status];
        } // if

        try {
            $client = Services_Arr_Config::clientFor($this->_settings, $d['service'], $this->_services[$d['service']], 15);
            $imdb = self::imdbIdOf($fullSpot);
            $candidates = $this->lookupWith($client, $d, $imdb);
            $choice = self::sureMatch($candidates, $d, $imdb);
            if ($choice === null) {
                return ['ok' => false, 'added' => false, 'status' => $status,
                    'message' => sprintf(_('Not sure which title this is, so it was not added to %s. Use "Add to %s" on the spot to pick it.'), $name, $name), ];
            } // if

            $item = $this->addCandidate($client, $d, $choice, []);
            $added = empty($choice['id']);
            $status = $this->statusForSpot($fullSpot);

            return ['ok' => true, 'added' => $added, 'status' => $status,
                'message' => $added
                    ? sprintf(_('Added %s to %s.'), self::displayTitle($item), $name)
                    : sprintf(_('%s is already in %s.'), self::displayTitle($item), $name), ];
        } catch (Exception $x) {
            return ['ok' => false, 'added' => false, 'message' => $x->getMessage(), 'status' => $status];
        } // catch
    }

    public static function displayTitle(array $item)
    {
        return (string) ($item['title'] ?? '').(!empty($item['year']) ? ' ('.(int) $item['year'].')' : '');
    }

    /*
     * Search results from Radarr / Sonarr for a spot, for the "Add to" dialog
     */
    public function lookup(array $fullSpot)
    {
        $d = $this->describe($fullSpot);
        if ($d === null) {
            throw new Services_Arr_Exception(_('Radarr and Sonarr are only used for movie and series spots.'));
        } // if

        $client = Services_Arr_Config::clientFor($this->_settings, $d['service'], $this->_services[$d['service']], 15);
        $imdb = self::imdbIdOf($fullSpot);
        $candidates = $this->lookupWith($client, $d, $imdb);
        $sure = self::sureMatch($candidates, $d, $imdb);

        $list = [];
        foreach (array_slice($candidates, 0, 8) as $c) {
            $list[] = [
                'ref'     => self::refOf($d['service'], $c),
                'title'   => (string) ($c['title'] ?? ''),
                'year'    => (int) ($c['year'] ?? 0),
                'imdbid'  => (string) ($c['imdbId'] ?? ''),
                'inlib'   => !empty($c['id']),
                'seasons' => count(array_filter((array) ($c['seasons'] ?? []), function ($s) { return (int) ($s['seasonNumber'] ?? 0) > 0; })),
                'best'    => ($sure !== null && self::refOf($d['service'], $sure) === self::refOf($d['service'], $c)),
            ];
        } // foreach

        $options = $this->addOptions($client, $d['service']);

        return ['service' => $d['service'], 'kind' => $d['kind'], 'candidates' => $list, 'options' => $options, 'season' => $d['parsed']['season']];
    }

    /*
     * Add the candidate the user picked in the dialog
     */
    public function addPicked(array $fullSpot, $ref, array $overrides)
    {
        $d = $this->describe($fullSpot);
        if ($d === null) {
            throw new Services_Arr_Exception(_('Radarr and Sonarr are only used for movie and series spots.'));
        } // if

        $client = Services_Arr_Config::clientFor($this->_settings, $d['service'], $this->_services[$d['service']], 15);
        $candidates = $this->lookupWith($client, $d, self::imdbIdOf($fullSpot));
        foreach ($candidates as $c) {
            if (self::refOf($d['service'], $c) === (string) $ref) {
                $item = $this->addCandidate($client, $d, $c, $overrides);

                return ['added' => empty($c['id']), 'item' => $item, 'status' => $this->statusForSpot($fullSpot)];
            } // if
        } // foreach

        throw new Services_Arr_Exception(_('That title is no longer in the search results. Search again.'));
    }

    private static function refOf($service, array $candidate)
    {
        return ($service === Services_Arr_Client::RADARR) ? 'tmdb:'.(int) ($candidate['tmdbId'] ?? 0) : 'tvdb:'.(int) ($candidate['tvdbId'] ?? 0);
    }

    private function lookupWith(Services_Arr_Client $client, array $d, $imdb)
    {
        $results = [];
        if ($imdb !== '') {
            try {
                $found = $client->lookupImdb($imdb);
                // Radarr answers with one movie, Sonarr with a list
                $results = isset($found['title']) ? [$found] : array_values(array_filter($found, 'is_array'));
            } catch (Services_Arr_Exception $x) {
                if ($x->getCode() === 401) {
                    throw $x;
                } // if
                $results = [];
            } // catch
        } // if

        if (empty($results)) {
            $term = $d['parsed']['title'].(($d['kind'] === Services_Arr_Title::KIND_MOVIE && $d['parsed']['year'] > 0) ? ' '.$d['parsed']['year'] : '');
            $results = array_values(array_filter($client->lookup($term), 'is_array'));
        } // if

        return $results;
    }

    /*
     * The one candidate we are sure about, or null
     */
    public static function sureMatch(array $candidates, array $d, $imdb)
    {
        if ($imdb !== '') {
            foreach ($candidates as $c) {
                if (strtolower((string) ($c['imdbId'] ?? '')) === $imdb) {
                    return $c;
                } // if
            } // foreach
        } // if

        $key = $d['parsed']['key'];
        $year = (int) $d['parsed']['year'];
        $exact = [];
        foreach ($candidates as $c) {
            $titles = [(string) ($c['title'] ?? ''), (string) ($c['originalTitle'] ?? '')];
            foreach ((array) ($c['alternateTitles'] ?? []) as $alt) {
                $titles[] = (string) ($alt['title'] ?? '');
            } // foreach
            foreach ($titles as $t) {
                $tk = Services_Arr_Title::key($t);
                $tk2 = Services_Arr_Title::key(preg_replace('/\s*\([^)]*\)\s*$/', '', $t));
                if ($key !== '' && ($tk === $key || $tk2 === $key)) {
                    $exact[] = $c;
                    break;
                } // if
            } // foreach
        } // foreach

        if ($year > 0) {
            $byYear = array_values(array_filter($exact, function ($c) use ($year) { return abs((int) ($c['year'] ?? 0) - $year) <= 1; }));
            if (count($byYear) >= 1) {
                usort($byYear, function ($a, $b) use ($year) { return abs((int) $a['year'] - $year) <=> abs((int) $b['year'] - $year); });

                return $byYear[0];
            } // if

            return null;
        } // if

        return (count($exact) === 1) ? $exact[0] : null;
    }

    /*
     * Root folders and quality profiles, for the dialog
     */
    private function addOptions(Services_Arr_Client $client, $service)
    {
        $config = $this->_services[$service];

        return [
            'rootfolders' => $client->rootFolders(),
            'profiles'    => $client->qualityProfiles(),
            'rootfolder'  => $config['rootfolder'],
            'profile'     => (int) $config['profile'],
        ];
    }

    private function addCandidate(Services_Arr_Client $client, array $d, array $candidate, array $overrides)
    {
        $service = $d['service'];

        // already in the library (our copy was out of date)
        if (!empty($candidate['id'])) {
            $this->library()->storeItem($this->userId(), $service, $candidate);

            return $candidate;
        } // if

        $config = $this->_services[$service];
        $root = (string) ($overrides['rootfolder'] ?? $config['rootfolder']);
        $profile = (int) ($overrides['profile'] ?? $config['profile']);
        if ($root === '') {
            $folders = $client->rootFolders();
            if (empty($folders)) {
                throw new Services_Arr_Exception(sprintf(_('%s has no root folder yet. Add one in %s under Settings > Media Management.'), ucfirst($service), ucfirst($service)));
            } // if
            $root = $folders[0]['path'];
        } // if
        if ($profile <= 0) {
            $profiles = $client->qualityProfiles();
            if (empty($profiles)) {
                throw new Services_Arr_Exception(sprintf(_('%s has no quality profile.'), ucfirst($service)));
            } // if
            $profile = $profiles[0]['id'];
        } // if

        $item = $candidate;
        unset($item['id']);
        $item['qualityProfileId'] = $profile;
        $item['rootFolderPath'] = $root;
        $item['monitored'] = true;

        if ($service === Services_Arr_Client::RADARR) {
            $item['minimumAvailability'] = $item['minimumAvailability'] ?? 'released';
            // SABnzbd is already downloading it, so Radarr should not search itself
            $item['addOptions'] = ['searchForMovie' => false];
        } else {
            $item['seasonFolder'] = true;
            $item['seasons'] = self::monitorSeasons((array) ($item['seasons'] ?? []), $d['parsed']['season']);
            // "unknown" makes Sonarr follow the monitored flags of the seasons above
            $item['addOptions'] = ['monitor' => 'unknown', 'searchForMissingEpisodes' => false, 'searchForCutoffUnmetEpisodes' => false];
            $languageProfile = (int) ($config['languageprofile'] ?? 0);
            if ($languageProfile <= 0) {
                $languages = $client->languageProfiles();
                $languageProfile = empty($languages) ? 0 : $languages[0]['id'];
            } // if
            if ($languageProfile > 0) {
                $item['languageProfileId'] = $languageProfile;
            } // if
        } // else

        $added = $client->add($item);
        if (!is_array($added) || empty($added['id'])) {
            throw new Services_Arr_Exception(sprintf(_('%s did not confirm that it added the title.'), ucfirst($service)));
        } // if

        $this->library()->storeItem($this->userId(), $service, $added);

        return $added;
    }

    /*
     * Only the current (latest) season is monitored, plus the season of
     * the spot when that is an older one. Specials (season 0) never.
     */
    public static function monitorSeasons(array $seasons, $spotSeason)
    {
        $latest = 0;
        foreach ($seasons as $season) {
            $latest = max($latest, (int) ($season['seasonNumber'] ?? 0));
        } // foreach

        foreach ($seasons as &$season) {
            $number = (int) ($season['seasonNumber'] ?? 0);
            $season['monitored'] = ($number > 0) && ($number === $latest || ($spotSeason !== null && $number === (int) $spotSeason));
        } // foreach
        unset($season);

        return $seasons;
    }

    // ------------------------------------------------------------------
    // refreshing
    // ------------------------------------------------------------------

    /*
     * Refresh libraries that are older than the user's interval, after the
     * page has been sent so nobody waits for it.
     */
    private function scheduleRefresh()
    {
        if ($this->_refreshScheduled || PHP_SAPI === 'cli') {
            return;
        } // if

        $due = [];
        foreach (array_keys($this->_services) as $service) {
            if ($this->library()->isDue($this->userId(), $service, $this->_config['interval'])) {
                $due[] = $service;
            } // if
        } // foreach
        if (empty($due)) {
            return;
        } // if

        $this->_refreshScheduled = true;
        register_shutdown_function(function () use ($due) {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            } // elseif
            ignore_user_abort(true);
            $this->refreshServices($due, false);
        });
    }

    /*
     * Refresh now. With $force the interval is ignored.
     * Returns service => ['ok' => bool, 'items' => int, 'message' => string]
     */
    public function refreshServices(array $services, $force)
    {
        $result = [];
        foreach ($services as $service) {
            if (!$this->hasService($service)) {
                continue;
            } // if

            try {
                $interval = $force ? 0 : $this->_config['interval'];
                if (!$force && !$this->library()->claim($this->userId(), $service, $interval)) {
                    continue;
                } // if
                if ($force) {
                    $this->library()->claim($this->userId(), $service, 0);
                } // if
                $client = Services_Arr_Config::clientFor($this->_settings, $service, $this->_services[$service], 60);
                $items = $this->library()->refresh($this->userId(), $client);
                $result[$service] = ['ok' => true, 'items' => $items, 'message' => ''];
            } catch (Exception $x) {
                $result[$service] = ['ok' => false, 'items' => 0, 'message' => $x->getMessage()];
            } // catch
        } // foreach

        return $result;
    }
}
