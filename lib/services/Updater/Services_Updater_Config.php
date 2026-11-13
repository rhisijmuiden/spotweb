<?php

/*
 * Updater settings, kept as one JSON row in the settings table.
 *
 * Two channels: production follows the `main` branch, test follows
 * `develop`. The method says how files are replaced:
 *   auto    - git when possible, otherwise the archive download
 *   git     - git fetch + checkout + fast-forward (needs exec/proc_open)
 *   archive - download the zip from GitHub and copy it over the code
 *   webhook - ask the hosting panel to deploy (its deploy webhook URL)
 */
class Services_Updater_Config
{
    const ROW = 'updater_config';
    const DEFAULT_REPO = 'rhisijmuiden/spotweb';

    const CHANNELS = ['production' => 'main', 'test' => 'develop'];
    const METHODS = ['auto', 'git', 'archive', 'webhook'];

    public static function defaults()
    {
        return ['repo' => self::DEFAULT_REPO, 'channel' => '', 'method' => 'auto', 'webhook' => '', 'token' => ''];
    }

    public static function load(dbeng_abs $conn)
    {
        $config = array_merge(self::defaults(), array_intersect_key(Services_Updater_Store::get($conn, self::ROW) ?? [], self::defaults()));

        // ownsettings.php may pin the repository, e.g. for a fork
        $settings = Services_Settings_Container::singleton();
        if ($settings->exists('updater_repo') && Services_Updater_GitHub::validRepo($settings->get('updater_repo'))) {
            $config['repo'] = $settings->get('updater_repo');
        } // if

        if (!isset(self::CHANNELS[$config['channel']])) {
            // follow what is installed; a fresh install starts on production
            $installed = (new Services_Updater_Installed())->source();
            $config['channel'] = ($installed['branch'] === self::branches()['test']) ? 'test' : 'production';
        } // if

        return $config;
    }

    /*
     * The branch per channel; ownsettings.php can rename them with
     * $settings['updater_branches'] = ['production' => 'main', 'test' => 'develop'];
     */
    public static function branches()
    {
        $branches = self::CHANNELS;
        $settings = Services_Settings_Container::singleton();
        $custom = $settings->exists('updater_branches') ? $settings->get('updater_branches') : null;
        if (is_array($custom)) {
            foreach ($branches as $channel => $default) {
                if (isset($custom[$channel]) && Services_Updater_GitHub::validBranch($custom[$channel])) {
                    $branches[$channel] = $custom[$channel];
                } // if
            } // foreach
        } // if

        return $branches;
    }

    public static function branch(array $config)
    {
        $branches = self::branches();

        return $branches[$config['channel']] ?? $branches['production'];
    }

    /*
     * Validates and stores the form; returns a list of errors
     */
    public static function save(dbeng_abs $conn, array $form)
    {
        $config = self::load($conn);
        $errors = [];

        if (isset($form['channel'])) {
            if (isset(self::CHANNELS[$form['channel']])) {
                $config['channel'] = $form['channel'];
            } else {
                $errors[] = _('Unknown update channel');
            } // else
        } // if

        if (isset($form['method'])) {
            if (in_array($form['method'], self::METHODS, true)) {
                $config['method'] = $form['method'];
            } else {
                $errors[] = _('Unknown update method');
            } // else
        } // if

        if (isset($form['webhook'])) {
            $url = trim((string) $form['webhook']);
            if ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) || stripos($url, 'https://') !== 0)) {
                $errors[] = _('The deploy webhook must be an https:// address');
            } else {
                $config['webhook'] = $url;
            } // else
        } // if

        if (isset($form['token'])) {
            $token = trim((string) $form['token']);
            if (!empty($form['cleartoken'])) {
                $config['token'] = '';
            } elseif ($token !== '') {
                if (!preg_match('/^[A-Za-z0-9_]{20,255}$/', $token)) {
                    $errors[] = _('That does not look like a GitHub token');
                } else {
                    $config['token'] = $token;
                } // else
            } // elseif
        } // if

        if ($config['method'] === 'webhook' && $config['webhook'] === '') {
            $errors[] = _('Enter the deploy webhook address to update through your hosting panel');
        } // if

        if (empty($errors)) {
            Services_Updater_Store::put($conn, self::ROW, $config);
        } // if

        return $errors;
    }

    /*
     * The config without secrets, for the browser
     */
    public static function forDisplay(array $config)
    {
        $config['hastoken'] = ($config['token'] !== '');
        unset($config['token']);
        $config['branch'] = self::branch($config);

        return $config;
    }
}
