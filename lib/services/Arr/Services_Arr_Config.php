<?php

/*
 * A user's Radarr and Sonarr settings, from their preferences.
 *
 * prefs['arr'] = [
 *   'radarr'   => ['enabled', 'url', 'apikey', 'rootfolder', 'profile', 'category'],
 *   'sonarr'   => ['enabled', 'url', 'apikey', 'rootfolder', 'profile', 'category', 'languageprofile'],
 *   'interval' => minutes between library refreshes,
 * ]
 *
 * The API keys are stored encrypted with a key that only this Spotweb
 * installation has (derived from its own secrets in the settings table).
 */
class Services_Arr_Config
{
    const SECRET_PREFIX = 'enc1:';
    const DEFAULT_INTERVAL = 15;

    public static function defaults()
    {
        return [
            'radarr'   => ['enabled' => false, 'url' => '', 'apikey' => '', 'rootfolder' => '', 'profile' => 0, 'category' => 'movies'],
            'sonarr'   => ['enabled' => false, 'url' => '', 'apikey' => '', 'rootfolder' => '', 'profile' => 0, 'category' => 'tv', 'languageprofile' => 0],
            'interval' => self::DEFAULT_INTERVAL,
        ];
    }

    public static function intervals()
    {
        return [5, 15, 30, 60, 240];
    }

    /*
     * The admin can switch the whole feature off in Admin > API & RSS
     */
    public static function siteEnabled(Services_Settings_Container $settings)
    {
        return $settings->isEnabled('arr_enabled');
    }

    /*
     * The settings of one user, merged over the defaults
     */
    public static function forPrefs($prefs)
    {
        $config = self::defaults();
        $arr = (is_array($prefs) && isset($prefs['arr']) && is_array($prefs['arr'])) ? $prefs['arr'] : [];
        foreach ([Services_Arr_Client::RADARR, Services_Arr_Client::SONARR] as $service) {
            if (isset($arr[$service]) && is_array($arr[$service])) {
                $config[$service] = array_merge($config[$service], array_intersect_key($arr[$service], $config[$service]));
            } // if
        } // foreach
        if (isset($arr['interval']) && in_array((int) $arr['interval'], self::intervals(), true)) {
            $config['interval'] = (int) $arr['interval'];
        } // if

        return $config;
    }

    /*
     * Services this user has switched on and filled in
     */
    public static function activeServices(Services_Settings_Container $settings, array $currentSession)
    {
        if (!self::siteEnabled($settings) || !self::userMayUse($currentSession)) {
            return [];
        } // if

        $config = self::forPrefs($currentSession['user']['prefs'] ?? []);
        $active = [];
        foreach ([Services_Arr_Client::RADARR, Services_Arr_Client::SONARR] as $service) {
            if (!empty($config[$service]['enabled']) && $config[$service]['url'] !== '' && $config[$service]['apikey'] !== '') {
                $active[$service] = $config[$service];
            } // if
        } // foreach

        return $active;
    }

    /*
     * Anonymous visitors never have a library; everyone else needs the
     * right to send downloads to a download client
     */
    public static function userMayUse(array $currentSession)
    {
        if ((int) ($currentSession['user']['userid'] ?? 0) <= SPOTWEB_ANONYMOUS_USERID) {
            return false;
        } // if
        if (!isset($currentSession['security'])) {
            return false;
        } // if

        return $currentSession['security']->allowed(SpotSecurity::spotsec_download_integration, '');
    }

    public static function serviceForKind($kind)
    {
        if ($kind === Services_Arr_Title::KIND_MOVIE) {
            return Services_Arr_Client::RADARR;
        } // if
        if ($kind === Services_Arr_Title::KIND_SERIES) {
            return Services_Arr_Client::SONARR;
        } // if

        return null;
    }

    public static function clientFor(Services_Settings_Container $settings, $service, array $serviceConfig, $timeout = 10)
    {
        return new Services_Arr_Client($service, $serviceConfig['url'], self::decrypt($settings, $serviceConfig['apikey']), $timeout);
    }

    private static function cipherKey(Services_Settings_Container $settings)
    {
        return hash('sha256', 'spotweb-arr|'.$settings->get('xsrfsecret').'|'.$settings->get('pass_salt'), true);
    }

    public static function encrypt(Services_Settings_Container $settings, $plain)
    {
        $plain = (string) $plain;
        if ($plain === '' || strpos($plain, self::SECRET_PREFIX) === 0) {
            return $plain;
        } // if

        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::cipherKey($settings), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            return $plain;
        } // if

        return self::SECRET_PREFIX.base64_encode($iv.$tag.$cipher);
    }

    public static function decrypt(Services_Settings_Container $settings, $stored)
    {
        $stored = (string) $stored;
        if (strpos($stored, self::SECRET_PREFIX) !== 0) {
            return $stored;
        } // if

        $raw = base64_decode(substr($stored, strlen(self::SECRET_PREFIX)), true);
        if ($raw === false || strlen($raw) < 29) {
            return '';
        } // if
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::cipherKey($settings), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));

        return ($plain === false) ? '' : $plain;
    }

    /*
     * The last four characters, to show which key is saved without showing it
     */
    public static function maskedKey(Services_Settings_Container $settings, $stored)
    {
        $plain = self::decrypt($settings, $stored);
        if ($plain === '') {
            return '';
        } // if

        return str_repeat('•', 12).substr($plain, -4);
    }

    /*
     * Clean up the arr part of a submitted preferences form. An empty API
     * key field keeps the saved key, so the key never has to be sent back
     * to the browser.
     */
    public static function fromForm(Services_Settings_Container $settings, $submitted, $current, array &$errors)
    {
        $submitted = is_array($submitted) ? $submitted : [];
        $current = self::forPrefs(['arr' => is_array($current) ? $current : []]);
        $result = self::defaults();

        foreach ([Services_Arr_Client::RADARR, Services_Arr_Client::SONARR] as $service) {
            $form = (isset($submitted[$service]) && is_array($submitted[$service])) ? $submitted[$service] : [];
            $name = ($service === Services_Arr_Client::RADARR) ? 'Radarr' : 'Sonarr';

            $result[$service]['enabled'] = !empty($form['enabled']);
            $result[$service]['url'] = Services_Arr_Client::normalizeUrl(mb_substr((string) ($form['url'] ?? ''), 0, 255));
            $key = trim((string) ($form['apikey'] ?? ''));
            if (!empty($form['clearkey'])) {
                $result[$service]['apikey'] = '';
            } elseif ($key === '') {
                $result[$service]['apikey'] = $current[$service]['apikey'];
            } else {
                $result[$service]['apikey'] = self::encrypt($settings, mb_substr($key, 0, 128));
            } // else
            $result[$service]['rootfolder'] = mb_substr(trim((string) ($form['rootfolder'] ?? '')), 0, 255);
            $result[$service]['profile'] = max(0, (int) ($form['profile'] ?? 0));
            $result[$service]['category'] = mb_substr(trim((string) ($form['category'] ?? $result[$service]['category'])), 0, 64);
            if ($service === Services_Arr_Client::SONARR) {
                $result[$service]['languageprofile'] = max(0, (int) ($form['languageprofile'] ?? $current[$service]['languageprofile']));
            } // if

            if ($result[$service]['url'] !== '' && !Services_Arr_Client::isValidUrl($result[$service]['url'])) {
                $errors[] = sprintf(_('The %s address is not a valid URL'), $name);
            } // if
            if ($result[$service]['enabled'] && ($result[$service]['url'] === '' || $result[$service]['apikey'] === '')) {
                $errors[] = sprintf(_('Enter the address and API key of %s, or switch it off'), $name);
            } // if
        } // foreach

        $interval = (int) ($submitted['interval'] ?? self::DEFAULT_INTERVAL);
        $result['interval'] = in_array($interval, self::intervals(), true) ? $interval : self::DEFAULT_INTERVAL;

        return $result;
    }
}
