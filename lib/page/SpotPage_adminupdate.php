<?php

/*
 * Admin > About & updates: version information, update check, channel
 * (production = main, test = develop) and "Update now".
 *
 * GET                     -> JSON with installed, config, check, job
 * POST submitcheck        -> runs the update check
 * POST submitsave         -> stores channel / method / webhook / token
 * POST submitstart        -> starts the update; answers with a job key and
 *                            keeps running after the browser got it
 *
 * Following a running update is done by Services_Updater_Endpoint, which
 * also works while the database upgrade is pending.
 */
class SpotPage_adminupdate extends SpotPage_Abs
{
    const CHECK_MAX_AGE = 900;

    private $_form;

    public function __construct(Dao_Factory $daoFactory, Services_Settings_Container $settings, array $currentSession, array $params)
    {
        parent::__construct($daoFactory, $settings, $currentSession);
        $this->_form = $params['adminupdateform'];
    }

    public function render()
    {
        $this->_spotSec->fatalPermCheck(SpotSecurity::spotsec_edit_settings, '');
        $this->sendExpireHeaders(true);

        $conn = $this->_daoFactory->getConnection();
        $config = Services_Updater_Config::load($conn);
        $checker = new Services_Updater_Check($conn);

        switch ($this->_form['action']) {
            case 'check':
                $checker->run($config);
                break;

            case 'save':
                $errors = Services_Updater_Config::save($conn, $this->_form);
                if (!empty($errors)) {
                    http_response_code(422);
                    $this->sendJson(['errors' => $errors] + $this->overview($conn));

                    return;
                } // if
                $newConfig = Services_Updater_Config::load($conn);
                if ($newConfig['channel'] !== $config['channel'] || $newConfig['repo'] !== $config['repo'] || $newConfig['token'] !== $config['token']) {
                    $checker->run($newConfig);
                } // if
                break;

            case 'start':
                $this->start($conn, $config, $checker);

                return;
        } // switch

        $this->sendJson($this->overview($conn));
    }

    /*
     * Everything the page shows, also used by the template
     */
    public static function overview(dbeng_abs $conn)
    {
        $config = Services_Updater_Config::load($conn);
        $root = (new Services_Updater_Installed())->root();
        $gitReason = (new Services_Updater_Git($root))->unavailableReason();

        return [
            'installed' => (new Services_Updater_Installed())->info(),
            'config'    => Services_Updater_Config::forDisplay($config),
            'check'     => (new Services_Updater_Check($conn))->cached(),
            'job'       => Services_Updater_Job::forDisplay(Services_Updater_Job::read($conn)),
            'methods'   => [
                'git'     => $gitReason,
                'archive' => self::archiveReason($root),
                'auto'    => self::resolveMethod($config, $root),
            ],
        ];
    }

    private function start(dbeng_abs $conn, array $config, Services_Updater_Check $checker)
    {
        if (Services_Updater_Job::isBusy(Services_Updater_Job::read($conn))) {
            http_response_code(409);
            $this->sendJson(['started' => false, 'message' => _('An update is already running.')]);

            return;
        } // if

        // always decide on fresh information
        $check = $checker->run($config);
        if (!in_array($check['state'], ['behind', 'switch', 'unknown'], true)) {
            http_response_code(409);
            $this->sendJson(['started' => false, 'message' => ($check['state'] === 'uptodate') ? _('Spotweb is already up to date.') : ($check['error'] ?: _('There is nothing to install.'))]);

            return;
        } // if
        if ($check['blocked'] !== '') {
            http_response_code(409);
            $this->sendJson(['started' => false, 'message' => $check['blocked']]);

            return;
        } // if

        $root = (new Services_Updater_Installed())->root();
        $method = self::resolveMethod($config, $root);
        if ($method === '') {
            http_response_code(409);
            $this->sendJson(['started' => false, 'message' => _('This server cannot update Spotweb by itself. Update it with git or your hosting panel, see docs/upgrading.md.')]);

            return;
        } // if

        $job = new Services_Updater_Job($conn);
        $key = $job->create($config, $method, $check, $this->_currentSession['user']['username']);

        // answer the browser first, then keep running
        $answer = json_encode(['started' => true, 'key' => $key, 'method' => $method]);
        ignore_user_abort(true);
        @set_time_limit(0);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Length: '.strlen($answer));
        header('Connection: close');
        echo $answer;
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            while (ob_get_level() > 0) {
                ob_end_flush();
            } // while
            flush();
        } // else

        $job->installFiles($config, $check, $this->workDir($root));
    }

    /*
     * The method "auto" stands for, or the configured one when it can be
     * used; '' when none works
     */
    public static function resolveMethod(array $config, $root)
    {
        $git = (new Services_Updater_Git($root))->unavailableReason() === '';
        $archive = self::archiveReason($root) === '';
        switch ($config['method']) {
            case 'git':     return $git ? 'git' : '';
            case 'archive': return $archive ? 'archive' : '';
            case 'webhook': return ($config['webhook'] !== '') ? 'webhook' : '';
        } // switch

        if ($git) {
            return 'git';
        } // if
        if ($archive) {
            return 'archive';
        } // if

        return ($config['webhook'] !== '') ? 'webhook' : '';
    }

    public static function archiveReason($root)
    {
        if (!Services_Updater_Archive::available()) {
            return _('PHP needs the zip and curl extensions for this.');
        } // if
        if (!is_writable($root) || !is_writable($root.'/lib')) {
            return _('The webserver may not write to the Spotweb folder.');
        } // if

        return '';
    }

    private function workDir($root)
    {
        $cache = $this->_daoFactory->getCachePath();
        if (!is_string($cache) || $cache === '') {
            $cache = $root.'/cache';
        } // if

        return rtrim($cache, '/\\').'/updater';
    }

    private function sendJson($data)
    {
        // a fresh form token, so a page left open stays usable
        $data['xsrf'] = SpotReq::generateXsrfCookie('adminupdateform');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
    }
} // class SpotPage_adminupdate
