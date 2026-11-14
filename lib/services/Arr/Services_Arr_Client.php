<?php

/*
 * Talks to the v3 API of Radarr or Sonarr. Every call is made by the
 * Spotweb server, so the address has to be reachable from there.
 */
class Services_Arr_Client
{
    const RADARR = 'radarr';
    const SONARR = 'sonarr';

    private $_service;
    private $_url;
    private $_apiKey;
    private $_timeout;

    /** @var callable|null replaces the HTTP call in tests: fn($method, $url, $headers, $body) => [status, body] */
    private static $_transport = null;

    public function __construct($service, $url, $apiKey, $timeout = 10)
    {
        if (!in_array($service, [self::RADARR, self::SONARR], true)) {
            throw new InvalidArgumentException('Unknown service '.$service);
        } // if

        $this->_service = $service;
        $this->_url = self::normalizeUrl($url);
        $this->_apiKey = trim((string) $apiKey);
        $this->_timeout = (int) $timeout;
    }

    public static function setTransport(?callable $transport)
    {
        self::$_transport = $transport;
    }

    /*
     * "192.168.1.20:7878/" -> "http://192.168.1.20:7878", keeps a URL base such as /radarr
     */
    public static function normalizeUrl($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        } // if
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = 'http://'.$url;
        } // if

        return rtrim($url, '/');
    }

    public static function isValidUrl($url)
    {
        $parts = parse_url(self::normalizeUrl($url));

        return is_array($parts) && !empty($parts['host'])
            && preg_match('/^(\[[0-9a-f:.]+\]|[a-z0-9.-]+)$/i', $parts['host'])
            && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true);
    }

    public function getService()
    {
        return $this->_service;
    }

    public function getUrl()
    {
        return $this->_url;
    }

    /*
     * Link to a movie or series in the web interface of Radarr / Sonarr
     */
    public static function webLink($service, $url, $slug)
    {
        if ($slug === '' || $url === '') {
            return '';
        } // if

        return self::normalizeUrl($url).(($service === self::RADARR) ? '/movie/' : '/series/').rawurlencode($slug);
    }

    public function systemStatus()
    {
        return $this->get('system/status');
    }

    public function rootFolders()
    {
        $folders = [];
        foreach ((array) $this->get('rootfolder') as $folder) {
            if (isset($folder['path'])) {
                $folders[] = ['path' => (string) $folder['path'], 'free' => (int) ($folder['freeSpace'] ?? 0)];
            } // if
        } // foreach

        return $folders;
    }

    public function qualityProfiles()
    {
        $profiles = [];
        foreach ((array) $this->get('qualityprofile') as $profile) {
            if (isset($profile['id'])) {
                $profiles[] = ['id' => (int) $profile['id'], 'name' => (string) ($profile['name'] ?? $profile['id'])];
            } // if
        } // foreach

        return $profiles;
    }

    /*
     * Sonarr v3 still needs a language profile when adding a series; v4 has none
     */
    public function languageProfiles()
    {
        try {
            $profiles = [];
            foreach ((array) $this->get('languageprofile') as $profile) {
                if (isset($profile['id'])) {
                    $profiles[] = ['id' => (int) $profile['id'], 'name' => (string) ($profile['name'] ?? $profile['id'])];
                } // if
            } // foreach

            return $profiles;
        } catch (Services_Arr_Exception $x) {
            return [];
        } // catch
    }

    /*
     * The whole library: all movies (Radarr) or all series (Sonarr)
     */
    public function library()
    {
        return (array) $this->get(($this->_service === self::RADARR) ? 'movie' : 'series');
    }

    /*
     * Search Radarr / Sonarr's own metadata (TMDB / TVDB) for a title
     */
    public function lookup($term)
    {
        $path = ($this->_service === self::RADARR) ? 'movie/lookup' : 'series/lookup';

        return (array) $this->get($path, ['term' => (string) $term]);
    }

    public function lookupImdb($imdbId)
    {
        if ($this->_service === self::RADARR) {
            return (array) $this->get('movie/lookup/imdb', ['imdbId' => (string) $imdbId]);
        } // if

        return $this->lookup('imdb:'.$imdbId);
    }

    public function add(array $item)
    {
        return $this->request('POST', ($this->_service === self::RADARR) ? 'movie' : 'series', [], $item);
    }

    public function get($path, array $query = [])
    {
        return $this->request('GET', $path, $query, null);
    }

    public function request($method, $path, array $query, $body)
    {
        if ($this->_url === '' || $this->_apiKey === '') {
            throw new Services_Arr_Exception(_('Enter the address and API key first.'));
        } // if

        $url = $this->_url.'/api/v3/'.ltrim($path, '/');
        if (!empty($query)) {
            $url .= '?'.http_build_query($query);
        } // if

        $headers = ['X-Api-Key: '.$this->_apiKey, 'Accept: application/json'];
        $payload = null;
        if ($body !== null) {
            $payload = json_encode($body);
            $headers[] = 'Content-Type: application/json';
        } // if

        if (self::$_transport !== null) {
            list($status, $response) = call_user_func(self::$_transport, $method, $url, $headers, $payload);
            $error = '';
        } else {
            list($status, $response, $error) = $this->curl($method, $url, $headers, $payload);
        } // else

        $name = ($this->_service === self::RADARR) ? 'Radarr' : 'Sonarr';
        if ($status === 0) {
            throw new Services_Arr_Exception(sprintf(_('Spotweb cannot reach %s at %s (%s). The address must be reachable from the Spotweb server.'), $name, $this->_url, $error));
        } // if
        if ($status === 401) {
            throw new Services_Arr_Exception(sprintf(_('%s refused the API key. Copy it again from %s under Settings > General.'), $name, $name), 401);
        } // if

        $data = json_decode((string) $response, true);
        if ($status >= 400) {
            $message = '';
            if (is_array($data)) {
                $first = isset($data[0]) && is_array($data[0]) ? $data[0] : $data;
                $message = (string) ($first['errorMessage'] ?? $first['message'] ?? '');
            } // if

            throw new Services_Arr_Exception(sprintf(_('%s answered with an error (HTTP %d)%s'), $name, $status, $message !== '' ? ': '.$message : ''), $status);
        } // if
        if ($data === null && trim((string) $response) !== '' && trim((string) $response) !== 'null') {
            throw new Services_Arr_Exception(sprintf(_('The answer from %s at %s is not what Spotweb expected. Check that the address points to %s itself.'), $name, $this->_url, $name));
        } // if

        return $data;
    }

    private function curl($method, $url, array $headers, $payload)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->_timeout);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Spotweb/'.SPOTWEB_VERSION);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        // home servers usually have a self-signed certificate
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string) $payload);
        } // if

        $response = curl_exec($ch);
        $status = ($response === false) ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = ($response === false) ? curl_error($ch) : '';

        return [$status, (string) $response, $error];
    }
}
