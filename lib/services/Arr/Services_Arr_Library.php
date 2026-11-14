<?php

/*
 * A local copy of each user's Radarr and Sonarr library.
 *
 * Spot lists never call Radarr or Sonarr: they look titles up in the
 * small arrlibrary table with one indexed query per page. The table is
 * refreshed in the background every few minutes (one API call per
 * service) and right after Spotweb adds a title.
 *
 * One row per title variant (main title, original title, alternative
 * titles), so Dutch and English names both match.
 */
class Services_Arr_Library
{
    const STATE_ONDISK = 'ondisk';
    const STATE_MISSING = 'missing';
    const STATE_COMPLETE = 'complete';
    const STATE_UNMONITORED = 'unmonitored';
    const STATE_NOTIN = 'notin';

    const INSERT_CHUNK = 200;

    /** @var dbeng_abs */
    private $_conn;

    public function __construct(dbeng_abs $conn)
    {
        $this->_conn = $conn;
    }

    // ------------------------------------------------------------------
    // refreshing
    // ------------------------------------------------------------------

    /*
     * Rows for one movie (Radarr) or series (Sonarr) as the API returns it
     */
    public static function rowsFor($service, array $item)
    {
        $titles = [(string) ($item['title'] ?? '')];
        if (!empty($item['originalTitle'])) {
            $titles[] = (string) $item['originalTitle'];
        } // if
        foreach ((array) ($item['alternateTitles'] ?? []) as $alt) {
            if (is_array($alt) && !empty($alt['title'])) {
                $titles[] = (string) $alt['title'];
            } // if
        } // foreach

        $keys = [];
        foreach ($titles as $title) {
            foreach ([$title, preg_replace('/\s*\([^)]*\)\s*$/', '', $title)] as $variant) {
                $key = Services_Arr_Title::key($variant);
                if ($key !== '') {
                    $keys[$key] = true;
                } // if
            } // foreach
        } // foreach

        $missing = 0;
        $detail = null;
        if ($service === Services_Arr_Client::RADARR) {
            if (!empty($item['hasFile'])) {
                $status = self::STATE_ONDISK;
            } elseif (!empty($item['monitored'])) {
                $status = self::STATE_MISSING;
            } else {
                $status = self::STATE_UNMONITORED;
            } // else
        } else {
            $stats = (array) ($item['statistics'] ?? []);
            $missing = max(0, (int) ($stats['episodeCount'] ?? 0) - (int) ($stats['episodeFileCount'] ?? 0));
            if (empty($item['monitored'])) {
                $status = self::STATE_UNMONITORED;
            } else {
                $status = ($missing > 0) ? self::STATE_MISSING : self::STATE_COMPLETE;
            } // else

            $seasons = [];
            foreach ((array) ($item['seasons'] ?? []) as $season) {
                if (!is_array($season) || !isset($season['seasonNumber'])) {
                    continue;
                } // if
                $s = (array) ($season['statistics'] ?? []);
                $seasons[(int) $season['seasonNumber']] = [
                    (int) ($s['episodeFileCount'] ?? 0),
                    (int) ($s['episodeCount'] ?? 0),
                    (int) ($s['totalEpisodeCount'] ?? 0),
                    empty($season['monitored']) ? 0 : 1,
                ];
            } // foreach
            $detail = json_encode($seasons);
        } // else

        $base = [
            'service' => $service,
            'arrid'   => (int) ($item['id'] ?? 0),
            'year'    => (int) ($item['year'] ?? 0),
            'imdbid'  => substr(preg_match('/^tt\d+$/', (string) ($item['imdbId'] ?? '')) ? (string) $item['imdbId'] : '', 0, 16),
            'title'   => mb_substr((string) ($item['title'] ?? ''), 0, 255),
            'slug'    => mb_substr((string) ($item['titleSlug'] ?? ''), 0, 255),
            'status'  => $status,
            'missing' => $missing,
            'detail'  => $detail,
        ];

        $rows = [];
        foreach (array_keys($keys) as $key) {
            $rows[] = $base + ['titlekey' => (string) $key];
        } // foreach

        return $rows;
    }

    /*
     * Is this user's copy older than the interval (or never made)?
     */
    public function isDue($userId, $service, $intervalMinutes)
    {
        $row = $this->getSyncRow($userId, $service);

        return ($row === null) || ((int) $row['lastsync'] < time() - 60 * (int) $intervalMinutes);
    }

    /*
     * Marks a refresh as started so parallel requests do not all start one.
     * Returns false when another request already did.
     */
    public function claim($userId, $service, $intervalMinutes)
    {
        $now = time();
        $params = [
            ':userid'    => [(int) $userId, PDO::PARAM_INT],
            ':service'   => [$service, PDO::PARAM_STR],
            ':now'       => [$now, PDO::PARAM_INT],
            ':threshold' => [$now - 60 * (int) $intervalMinutes, PDO::PARAM_INT],
        ];

        $stmt = $this->_conn->exec('UPDATE arrsync SET lastsync = :now WHERE userid = :userid AND service = :service AND lastsync < :threshold', $params);
        $count = $stmt->rowCount();
        $stmt->closeCursor();
        if ($count > 0) {
            return true;
        } // if

        if ($this->getSyncRow($userId, $service) !== null) {
            return false;
        } // if

        try {
            $this->_conn->modify(
                "INSERT INTO arrsync(userid, service, lastsync, lastok, items, error) VALUES(:userid, :service, :now, 0, 0, '')",
                [':userid' => $params[':userid'], ':service' => $params[':service'], ':now' => $params[':now']]
            );

            return true;
        } catch (Exception $x) {
            // a parallel request inserted the row first
            return false;
        } // catch
    }

    /*
     * Fetch the whole library from Radarr / Sonarr and replace our copy.
     * Returns the number of movies or series.
     */
    public function refresh($userId, Services_Arr_Client $client)
    {
        $service = $client->getService();

        try {
            $items = $client->library();
        } catch (Services_Arr_Exception $x) {
            $this->recordResult($userId, $service, false, 0, $x->getMessage());

            throw $x;
        } // catch

        $rows = [];
        foreach ($items as $item) {
            if (is_array($item) && !empty($item['id'])) {
                foreach (self::rowsFor($service, $item) as $row) {
                    $rows[] = $row;
                } // foreach
            } // if
        } // foreach

        $this->_conn->beginTransaction();

        try {
            $this->_conn->modify('DELETE FROM arrlibrary WHERE userid = :userid AND service = :service', [
                ':userid'  => [(int) $userId, PDO::PARAM_INT],
                ':service' => [$service, PDO::PARAM_STR],
            ]);
            $this->insertRows($userId, $rows);
            $this->_conn->commit();
        } catch (Exception $x) {
            $this->_conn->rollback();
            $this->recordResult($userId, $service, false, 0, $x->getMessage());

            throw $x;
        } // catch

        $this->recordResult($userId, $service, true, count($items), '');

        return count($items);
    }

    /*
     * Put one item in our copy right away, e.g. after Spotweb added it
     */
    public function storeItem($userId, $service, array $item)
    {
        if (empty($item['id'])) {
            return;
        } // if

        $this->_conn->modify('DELETE FROM arrlibrary WHERE userid = :userid AND service = :service AND arrid = :arrid', [
            ':userid'  => [(int) $userId, PDO::PARAM_INT],
            ':service' => [$service, PDO::PARAM_STR],
            ':arrid'   => [(int) $item['id'], PDO::PARAM_INT],
        ]);
        $this->insertRows($userId, self::rowsFor($service, $item));
    }

    /*
     * Forget a user's library, e.g. when they switch a service off
     */
    public function forget($userId, $service)
    {
        $params = [':userid' => [(int) $userId, PDO::PARAM_INT], ':service' => [$service, PDO::PARAM_STR]];
        $this->_conn->modify('DELETE FROM arrlibrary WHERE userid = :userid AND service = :service', $params);
        $this->_conn->modify('DELETE FROM arrsync WHERE userid = :userid AND service = :service', $params);
    }

    private function insertRows($userId, array $rows)
    {
        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            $values = [];
            $params = [':userid' => [(int) $userId, PDO::PARAM_INT]];
            foreach ($chunk as $i => $row) {
                $values[] = "(:userid, :s$i, :k$i, :a$i, :y$i, :i$i, :t$i, :g$i, :st$i, :m$i, :d$i)";
                $params[":s$i"] = [$row['service'], PDO::PARAM_STR];
                $params[":k$i"] = [$row['titlekey'], PDO::PARAM_STR];
                $params[":a$i"] = [$row['arrid'], PDO::PARAM_INT];
                $params[":y$i"] = [$row['year'], PDO::PARAM_INT];
                $params[":i$i"] = [$row['imdbid'], PDO::PARAM_STR];
                $params[":t$i"] = [$row['title'], PDO::PARAM_STR];
                $params[":g$i"] = [$row['slug'], PDO::PARAM_STR];
                $params[":st$i"] = [$row['status'], PDO::PARAM_STR];
                $params[":m$i"] = [$row['missing'], PDO::PARAM_INT];
                $params[":d$i"] = [$row['detail'], ($row['detail'] === null) ? PDO::PARAM_NULL : PDO::PARAM_STR];
            } // foreach

            $this->_conn->modify(
                'INSERT INTO arrlibrary(userid, service, titlekey, arrid, year, imdbid, title, slug, status, missing, detail) VALUES '.implode(',', $values),
                $params
            );
        } // foreach
    }

    private function recordResult($userId, $service, $ok, $items, $error)
    {
        $params = [
            ':userid'  => [(int) $userId, PDO::PARAM_INT],
            ':service' => [$service, PDO::PARAM_STR],
            ':now'     => [time(), PDO::PARAM_INT],
            ':okstamp' => [time(), PDO::PARAM_INT],
            ':items'   => [(int) $items, PDO::PARAM_INT],
            ':error'   => [mb_substr((string) $error, 0, 255), PDO::PARAM_STR],
        ];

        try {
            if ($this->getSyncRow($userId, $service) === null) {
                $this->_conn->modify(
                    "INSERT INTO arrsync(userid, service, lastsync, lastok, items, error) VALUES(:userid, :service, :now, 0, 0, '')",
                    [':userid' => $params[':userid'], ':service' => $params[':service'], ':now' => $params[':now']]
                );
            } // if
            if ($ok) {
                unset($params[':error']);
                $this->_conn->modify("UPDATE arrsync SET lastsync = :now, lastok = :okstamp, items = :items, error = '' WHERE userid = :userid AND service = :service", $params);
            } else {
                unset($params[':items'], $params[':okstamp']);
                $this->_conn->modify('UPDATE arrsync SET lastsync = :now, error = :error WHERE userid = :userid AND service = :service', $params);
            } // else
        } catch (Exception $x) {
            // the status line is informative only
        } // catch
    }

    public function getSyncRow($userId, $service)
    {
        $rows = $this->_conn->arrayQuery('SELECT userid, service, lastsync, lastok, items, error FROM arrsync WHERE userid = :userid AND service = :service', [
            ':userid'  => [(int) $userId, PDO::PARAM_INT],
            ':service' => [$service, PDO::PARAM_STR],
        ]);

        return empty($rows) ? null : $rows[0];
    }

    // ------------------------------------------------------------------
    // looking up
    // ------------------------------------------------------------------

    /*
     * Rows for the given title keys and IMDb ids, in one query.
     * Returns [service][titlekey][] and ['imdb'][imdbid][]
     */
    public function rowsForKeys($userId, array $services, array $keys, array $imdbIds = [])
    {
        $result = ['keys' => [], 'imdb' => []];
        $keys = array_values(array_unique(array_filter(array_map('strval', $keys))));
        $imdbIds = array_values(array_unique(array_filter(array_map('strval', $imdbIds))));
        if (empty($services) || (empty($keys) && empty($imdbIds))) {
            return $result;
        } // if

        $params = [':userid' => [(int) $userId, PDO::PARAM_INT]];
        $where = [];
        if (!empty($keys)) {
            $in = [];
            foreach ($keys as $i => $key) {
                $in[] = ":k$i";
                $params[":k$i"] = [$key, PDO::PARAM_STR];
            } // foreach
            $where[] = 'titlekey IN ('.implode(',', $in).')';
        } // if
        if (!empty($imdbIds)) {
            $in = [];
            foreach ($imdbIds as $i => $id) {
                $in[] = ":i$i";
                $params[":i$i"] = [$id, PDO::PARAM_STR];
            } // foreach
            $where[] = 'imdbid IN ('.implode(',', $in).')';
        } // if

        $rows = $this->_conn->arrayQuery(
            'SELECT service, titlekey, arrid, year, imdbid, title, slug, status, missing, detail
               FROM arrlibrary WHERE userid = :userid AND ('.implode(' OR ', $where).')',
            $params
        );

        $services = array_flip($services);
        foreach ($rows as $row) {
            if (!isset($services[$row['service']])) {
                continue;
            } // if
            $result['keys'][$row['service']][$row['titlekey']][] = $row;
            if ($row['imdbid'] !== '') {
                $result['imdb'][$row['service']][$row['imdbid']][] = $row;
            } // if
        } // foreach

        return $result;
    }

    /*
     * Pick the library item for one spot from the rows found for it.
     * Returns the row, STATE_NOTIN when the title is surely not there, or
     * null when we cannot tell (two different movies with this name).
     */
    public static function pick(array $rows, $service, array $parsed, $imdbId = '')
    {
        if ($imdbId !== '' && !empty($rows['imdb'][$service][$imdbId])) {
            return $rows['imdb'][$service][$imdbId][0];
        } // if

        $candidates = $rows['keys'][$service][$parsed['key']] ?? [];
        $byItem = [];
        foreach ($candidates as $row) {
            $byItem[(int) $row['arrid']] = $row;
        } // foreach

        if (empty($byItem)) {
            return self::STATE_NOTIN;
        } // if

        $year = (int) $parsed['year'];
        if ($year > 0) {
            $best = null;
            $bestDiff = 99;
            foreach ($byItem as $row) {
                $diff = ((int) $row['year'] === 0) ? 1 : abs((int) $row['year'] - $year);
                if ($diff < $bestDiff) {
                    $best = $row;
                    $bestDiff = $diff;
                } // if
            } // foreach

            if ($bestDiff <= 1) {
                return $best;
            } // if

            // a movie with this name but from another year is a different movie
            return ($service === Services_Arr_Client::RADARR) ? self::STATE_NOTIN : ((count($byItem) === 1) ? reset($byItem) : null);
        } // if

        return (count($byItem) === 1) ? reset($byItem) : null;
    }
}
