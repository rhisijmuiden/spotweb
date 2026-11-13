<?php

/**
 * Short lived file cache for complete API and RSS responses.
 *
 * Prowlarr, Sonarr and Radarr poll the same feeds over and over (and often
 * at the same moment). Building such a response costs several queries on a
 * large spots table, while the answer only changes when new spots arrive.
 *
 * A cached response is used when:
 *  - it was made for the same user, API key and request parameters;
 *  - no spot was added since (the highest spot id is part of the key);
 *  - it is younger than the time to live set in Admin > API & RSS.
 *
 * Only one PHP process builds a missing response at a time; others asking
 * for the same response wait for it instead of running the same queries.
 * That protects the (limited) PHP-FPM workers and the database when several
 * apps poll at once.
 */
class Services_Providers_ResponseCache
{
    /*
     * Default time to live in seconds, used when the admin did not set one
     */
    const DEFAULT_TTL = 600;

    /*
     * Maximum time to live which can be configured
     */
    const MAX_TTL = 3600;

    /*
     * How long a process waits for another one building the same response
     */
    const LOCK_WAIT_SECONDS = 60;

    private $_dir;
    private $_ttl;

    public function __construct($cachePath, $ttl)
    {
        $this->_dir = rtrim((string) $cachePath, '/\\').DIRECTORY_SEPARATOR.'responses';
        $this->_ttl = max(0, min(self::MAX_TTL, (int) $ttl));
    }

    // ctor

    /**
     * Returns the time to live configured in the settings.
     */
    public static function ttlFromSettings(Services_Settings_Container $settings)
    {
        if (!$settings->exists('api_cache_ttl')) {
            return self::DEFAULT_TTL;
        } // if

        return max(0, min(self::MAX_TTL, (int) $settings->get('api_cache_ttl')));
    }

    // ttlFromSettings

    /**
     * Returns the highest spot id. Part of every cache key, so a retrieve
     * which adds spots makes all cached responses obsolete at once.
     * Reading the maximum of the primary key is a single index lookup.
     */
    public static function spotGeneration(dbeng_abs $conn)
    {
        try {
            return (int) $conn->singleQuery('SELECT MAX(id) FROM spots');
        } catch (Exception $x) {
            return 0;
        } // catch
    }

    // spotGeneration

    public function isEnabled()
    {
        return $this->_ttl > 0;
    }

    // isEnabled

    /**
     * Creates a cache key from everything a response depends on.
     */
    public function makeKey(array $parts)
    {
        return sha1(json_encode($parts));
    }

    // makeKey

    /**
     * Returns a cached response as ['headers' => [...], 'body' => '...'],
     * or null when there is no usable one.
     */
    public function get($key)
    {
        if (!$this->isEnabled()) {
            return null;
        } // if

        $file = $this->fileName($key);
        $mtime = @filemtime($file);
        if (($mtime === false) || ((time() - $mtime) >= $this->_ttl)) {
            return null;
        } // if

        $content = @file_get_contents($file);
        if ($content === false) {
            return null;
        } // if

        /*
         * The first line holds the headers (as JSON), the rest is the body
         */
        $newline = strpos($content, "\n");
        if ($newline === false) {
            return null;
        } // if
        $headers = json_decode(substr($content, 0, $newline), true);
        if (!is_array($headers)) {
            return null;
        } // if

        return ['headers' => $headers, 'body' => (string) substr($content, $newline + 1)];
    }

    // get

    /**
     * Stores a response. Written to a temporary file and renamed, so a reader
     * never sees half a response.
     */
    public function put($key, array $headers, $body)
    {
        if (!$this->isEnabled() || !$this->ensureDir()) {
            return false;
        } // if

        $file = $this->fileName($key);
        $tmpFile = $file.'.'.getmypid().'.tmp';
        if (@file_put_contents($tmpFile, json_encode(array_values($headers))."\n".$body) === false) {
            return false;
        } // if
        if (!@rename($tmpFile, $file)) {
            @unlink($tmpFile);

            return false;
        } // if

        // Now and then remove responses nobody asked for anymore
        if (mt_rand(1, 50) === 1) {
            $this->purgeExpired();
        } // if

        return true;
    }

    // put

    /**
     * Returns the cached response for this key, or builds it with $producer.
     *
     * $producer must return ['headers' => [...], 'body' => '...'] for a
     * response which may be cached, or null for one which may not (an error).
     * The returned array has an extra 'cache' entry: HIT, MISS or OFF.
     */
    public function remember($key, callable $producer)
    {
        if (!$this->isEnabled()) {
            $response = $producer();

            return ($response === null) ? null : $response + ['cache' => 'OFF'];
        } // if

        $cached = $this->get($key);
        if ($cached !== null) {
            return $cached + ['cache' => 'HIT'];
        } // if

        /*
         * Let only one process build this response. The others wait for the
         * lock, and then find the response in the cache.
         */
        $lock = $this->lock($key);
        try {
            if ($lock !== null) {
                $cached = $this->get($key);
                if ($cached !== null) {
                    return $cached + ['cache' => 'HIT'];
                } // if
            } // if

            $response = $producer();
            if ($response !== null) {
                $this->put($key, $response['headers'], $response['body']);
                $response['cache'] = 'MISS';
            } // if

            return $response;
        } finally {
            $this->unlock($lock);
        } // finally
    }

    // remember

    /**
     * Removes all cached responses.
     */
    public function clear()
    {
        foreach ((array) @glob($this->_dir.DIRECTORY_SEPARATOR.'*') as $file) {
            @unlink($file);
        } // foreach
    }

    // clear

    /**
     * Removes cached responses which are too old to be used.
     */
    public function purgeExpired()
    {
        $oldest = time() - max($this->_ttl, 60) - 60;
        foreach ((array) @glob($this->_dir.DIRECTORY_SEPARATOR.'*') as $file) {
            $mtime = @filemtime($file);
            if (($mtime !== false) && ($mtime < $oldest)) {
                @unlink($file);
            } // if
        } // foreach
    }

    // purgeExpired

    private function fileName($key)
    {
        return $this->_dir.DIRECTORY_SEPARATOR.preg_replace('/[^a-f0-9]/', '', $key).'.cache';
    }

    // fileName

    private function ensureDir()
    {
        if (is_dir($this->_dir)) {
            return is_writable($this->_dir);
        } // if

        return @mkdir($this->_dir, 0775, true) || is_dir($this->_dir);
    }

    // ensureDir

    /**
     * Takes an exclusive lock for this key, waiting at most LOCK_WAIT_SECONDS.
     * Returns null when no lock could be taken; the caller then simply builds
     * the response itself.
     */
    private function lock($key)
    {
        if (!$this->ensureDir()) {
            return null;
        } // if

        $handle = @fopen($this->fileName($key).'.lock', 'c');
        if ($handle === false) {
            return null;
        } // if

        $deadline = microtime(true) + self::LOCK_WAIT_SECONDS;
        do {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                return $handle;
            } // if
            usleep(50000);
        } while (microtime(true) < $deadline);

        fclose($handle);

        return null;
    }

    // lock

    private function unlock($handle)
    {
        if ($handle !== null) {
            flock($handle, LOCK_UN);
            fclose($handle);
        } // if
    }

    // unlock
} // Services_Providers_ResponseCache
