<?php

/*
 * Counts what Spotweb handles: requests per day, per user and per channel
 * (web interface, Newznab API, RSS feed), and every NZB that is fetched.
 *
 * Counting is one small UPDATE per request on a tiny table, so it does not
 * slow down the API. It never throws: statistics must not break a request,
 * for example before bin/upgrade-db.php has created the tables.
 */
class Services_Stats_Requests
{
    const CHANNEL_WEB = 'web';
    const CHANNEL_API = 'api';
    const CHANNEL_RSS = 'rss';

    /** @var dbeng_abs */
    private $_conn;

    public function __construct(dbeng_abs $conn)
    {
        $this->_conn = $conn;
    }

    /*
     * Which channel a request came in through
     */
    public static function channelFor($page, $hasApiKey)
    {
        if ($page === 'newznabapi') {
            return self::CHANNEL_API;
        } elseif ($page === 'rss') {
            return self::CHANNEL_RSS;
        } elseif ($hasApiKey) {
            // NZB downloads and other calls made with an API key, e.g. from an RSS reader
            return self::CHANNEL_API;
        } // elseif

        return self::CHANNEL_WEB;
    }

    /*
     * Short name of the app that sent a request, from its User-Agent
     */
    public static function clientName($userAgent)
    {
        $userAgent = (string) $userAgent;
        foreach (['Prowlarr', 'Sonarr', 'Radarr', 'Lidarr', 'Readarr', 'Whisparr', 'Mylar', 'LazyLibrarian', 'NZBHydra', 'SABnzbd', 'NZBGet'] as $app) {
            if (stripos($userAgent, $app) !== false) {
                return $app;
            } // if
        } // foreach

        if ($userAgent === '') {
            return '';
        } // if

        return (preg_match('/Mozilla|Chrome|Safari|Firefox|Edg\//i', $userAgent)) ? 'Browser' : mb_substr(strtok($userAgent, ' /'), 0, 64);
    }

    public static function today()
    {
        return (int) date('Ymd');
    }

    public function record($userId, $channel)
    {
        $params = [
            ':day'     => [self::today(), PDO::PARAM_INT],
            ':userid'  => [(int) $userId, PDO::PARAM_INT],
            ':channel' => [$channel, PDO::PARAM_STR],
        ];

        try {
            $updated = $this->_conn->exec('UPDATE requeststats SET requests = requests + 1
                                            WHERE day = :day AND userid = :userid AND channel = :channel', $params);
            $count = $updated->rowCount();
            $updated->closeCursor();

            if ($count === 0) {
                try {
                    $this->_conn->modify('INSERT INTO requeststats(day, userid, channel, requests)
                                            VALUES(:day, :userid, :channel, 1)', $params);
                } catch (Exception $x) {
                    // a parallel request inserted the row first
                    $this->_conn->modify('UPDATE requeststats SET requests = requests + 1
                                            WHERE day = :day AND userid = :userid AND channel = :channel', $params);
                } // catch
            } // if
        } catch (Exception $x) {
            // statistics are best effort
        } // catch
    }

    public function recordDownload($userId, $messageId, $channel, $client)
    {
        try {
            $this->_conn->modify(
                'INSERT INTO downloadhistory(userid, messageid, stamp, channel, client)
                    VALUES(:userid, :messageid, :stamp, :channel, :client)',
                [
                    ':userid'    => [(int) $userId, PDO::PARAM_INT],
                    ':messageid' => [(string) $messageId, PDO::PARAM_STR],
                    ':stamp'     => [time(), PDO::PARAM_INT],
                    ':channel'   => [(string) $channel, PDO::PARAM_STR],
                    ':client'    => [mb_substr((string) $client, 0, 64), PDO::PARAM_STR],
                ]
            );
        } catch (Exception $x) {
            // statistics are best effort
        } // catch
    }

    private static function dayAgo($days)
    {
        return (int) date('Ymd', strtotime('-'.(int) $days.' days'));
    }

    /*
     * Requests per day and channel for the last $days days, oldest first,
     * with days without traffic filled in as zero
     */
    public function getDaily($days)
    {
        $result = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $stamp = strtotime('-'.$i.' days');
            $result[(int) date('Ymd', $stamp)] = ['stamp' => $stamp, self::CHANNEL_WEB => 0, self::CHANNEL_API => 0, self::CHANNEL_RSS => 0, 'total' => 0];
        } // for

        $rows = $this->_conn->arrayQuery(
            'SELECT day, channel, SUM(requests) AS requests FROM requeststats
                WHERE day >= :since GROUP BY day, channel',
            [':since' => [self::dayAgo($days - 1), PDO::PARAM_INT]]
        );
        foreach ($rows as $row) {
            $day = (int) $row['day'];
            if (isset($result[$day][$row['channel']])) {
                $result[$day][$row['channel']] += (int) $row['requests'];
                $result[$day]['total'] += (int) $row['requests'];
            } // if
        } // foreach

        return array_values($result);
    }

    /*
     * Users with the most requests in the last $days days, split by channel
     */
    public function getTopUsers($days, $limit)
    {
        $rows = $this->_conn->arrayQuery(
            'SELECT r.userid, u.username, r.channel, SUM(r.requests) AS requests
                FROM requeststats r LEFT JOIN users u ON (u.id = r.userid)
                WHERE r.day >= :since
                GROUP BY r.userid, u.username, r.channel',
            [':since' => [self::dayAgo($days - 1), PDO::PARAM_INT]]
        );

        $users = [];
        foreach ($rows as $row) {
            $id = (int) $row['userid'];
            if (!isset($users[$id])) {
                $users[$id] = ['userid' => $id, 'username' => (string) $row['username'], self::CHANNEL_WEB => 0, self::CHANNEL_API => 0, self::CHANNEL_RSS => 0, 'total' => 0];
            } // if
            if (isset($users[$id][$row['channel']])) {
                $users[$id][$row['channel']] += (int) $row['requests'];
            } // if
            $users[$id]['total'] += (int) $row['requests'];
        } // foreach

        usort($users, function ($a, $b) { return $b['total'] <=> $a['total']; });

        return array_slice($users, 0, $limit);
    }

    /*
     * NZB downloads in the last $days days, per channel and per client app
     */
    public function getDownloadSummary($days)
    {
        $rows = $this->_conn->arrayQuery(
            'SELECT channel, client, COUNT(*) AS downloads FROM downloadhistory
                WHERE stamp >= :since GROUP BY channel, client',
            [':since' => [strtotime('-'.(int) $days.' days'), PDO::PARAM_INT]]
        );

        $summary = ['total' => 0, 'channels' => [], 'clients' => []];
        foreach ($rows as $row) {
            $n = (int) $row['downloads'];
            $summary['total'] += $n;
            $summary['channels'][$row['channel']] = ($summary['channels'][$row['channel']] ?? 0) + $n;
            $client = ($row['client'] === '') ? '?' : $row['client'];
            $summary['clients'][$client] = ($summary['clients'][$client] ?? 0) + $n;
        } // foreach
        arsort($summary['clients']);

        return $summary;
    }

    /*
     * Oldest and newest spot in the database, and how many there are
     */
    public function getSpotRange()
    {
        $row = $this->_conn->arrayQuery('SELECT MIN(stamp) AS oldest, MAX(stamp) AS newest FROM spots');
        $count = $this->_conn->singleQuery('SELECT COUNT(*) FROM spots');

        return ['oldest' => (int) ($row[0]['oldest'] ?? 0),
            'newest'     => (int) ($row[0]['newest'] ?? 0),
            'count'      => (int) $count, ];
    }
}
