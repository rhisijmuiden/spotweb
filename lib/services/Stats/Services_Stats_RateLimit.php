<?php

/*
 * Optional limits on API use, set in Admin > API & RSS:
 *
 *  - api_limit_minute:    API requests per user per minute (0 = no limit)
 *  - api_limit_day:       API requests per user per day (0 = no limit)
 *  - api_download_limit:  NZB downloads via the API per user per day (0 = no limit)
 *
 * The day limit reuses the counts in requeststats, so it costs nothing
 * extra. The minute limit keeps a counter per user and minute in the
 * ratelimit table. Like the statistics, a database problem never blocks
 * a request.
 */
class Services_Stats_RateLimit
{
    /** @var dbeng_abs */
    private $_conn;
    /** @var Services_Settings_Container */
    private $_settings;

    public function __construct(dbeng_abs $conn, Services_Settings_Container $settings)
    {
        $this->_conn = $conn;
        $this->_settings = $settings;
    }

    private function limit($name)
    {
        return max(0, (int) $this->_settings->get($name));
    }

    /*
     * Returns null when the request may go ahead, otherwise the number of
     * seconds the client should wait. Call after Services_Stats_Requests::record().
     */
    public function checkApiRequest($userId)
    {
        $perMinute = $this->limit('api_limit_minute');
        $perDay = $this->limit('api_limit_day');

        try {
            if ($perMinute > 0) {
                $window = (int) floor(time() / 60);
                $hits = $this->countMinute((int) $userId, $window);
                if ($hits > $perMinute) {
                    return 60 - (time() % 60);
                } // if
            } // if

            if ($perDay > 0) {
                $today = (int) $this->_conn->singleQuery(
                    'SELECT requests FROM requeststats WHERE day = :day AND userid = :userid AND channel = :channel',
                    [
                        ':day'     => [Services_Stats_Requests::today(), PDO::PARAM_INT],
                        ':userid'  => [(int) $userId, PDO::PARAM_INT],
                        ':channel' => [Services_Stats_Requests::CHANNEL_API, PDO::PARAM_STR],
                    ]
                );
                if ($today > $perDay) {
                    return strtotime('tomorrow') - time();
                } // if
            } // if
        } catch (Exception $x) {
            // never block because of a statistics problem
        } // catch

        return null;
    }

    /*
     * Returns null when another NZB may be downloaded via the API today,
     * otherwise the number of seconds until the limit resets
     */
    public function checkApiDownload($userId)
    {
        $perDay = $this->limit('api_download_limit');
        if ($perDay === 0) {
            return null;
        } // if

        try {
            $downloads = (int) $this->_conn->singleQuery(
                'SELECT COUNT(*) FROM downloadhistory WHERE userid = :userid AND stamp >= :since AND channel = :channel',
                [
                    ':userid'  => [(int) $userId, PDO::PARAM_INT],
                    ':since'   => [strtotime('today'), PDO::PARAM_INT],
                    ':channel' => [Services_Stats_Requests::CHANNEL_API, PDO::PARAM_STR],
                ]
            );
            if ($downloads >= $perDay) {
                return strtotime('tomorrow') - time();
            } // if
        } catch (Exception $x) {
            // never block because of a statistics problem
        } // catch

        return null;
    }

    private function countMinute($userId, $window)
    {
        $params = [':userid' => [$userId, PDO::PARAM_INT], ':window' => [$window, PDO::PARAM_INT]];

        $stmt = $this->_conn->exec('UPDATE ratelimit SET hits = hits + 1 WHERE userid = :userid AND timewindow = :window', $params);
        $updated = $stmt->rowCount();
        $stmt->closeCursor();

        if ($updated === 0) {
            try {
                $this->_conn->modify('INSERT INTO ratelimit(userid, timewindow, hits) VALUES(:userid, :window, 1)', $params);
            } catch (Exception $x) {
                $this->_conn->modify('UPDATE ratelimit SET hits = hits + 1 WHERE userid = :userid AND timewindow = :window', $params);
            } // catch

            // a new minute started: drop counters older than an hour
            $this->_conn->modify('DELETE FROM ratelimit WHERE timewindow < :old', [':old' => [$window - 60, PDO::PARAM_INT]]);
        } // if

        return (int) $this->_conn->singleQuery('SELECT hits FROM ratelimit WHERE userid = :userid AND timewindow = :window', $params);
    }

    /*
     * Sends a Newznab "limit reached" answer (code 500 or 501) with
     * HTTP 429 and Retry-After, which Prowlarr and the *arr apps honour
     */
    public static function sendLimitReached($code, $retryAfter)
    {
        http_response_code(429);
        header('Retry-After: '.max(1, (int) $retryAfter));
        header('Content-Type: text/xml; charset=utf-8');
        header('Cache-Control: private, no-store');
        $text = ($code === 501) ? 'Download limit reached' : 'Request limit reached';
        echo '<?xml version="1.0" encoding="UTF-8"?>'.PHP_EOL.'<error code="'.(int) $code.'" description="'.$text.'"/>';
    }
}
