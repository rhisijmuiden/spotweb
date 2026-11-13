<?php

/*
 * The parts of the updater that must work while Spotweb itself does not:
 * between the new files and the database upgrade every normal page
 * answers "run upgrade-db". index.php calls these before booting.
 */
class Services_Updater_Endpoint
{
    /*
     * ?page=adminupdate&job=<key>[&action=status|retrydb]
     * The key was handed to the admin who started the update.
     */
    public static function handleJobRequest($key, $action)
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        header('X-Robots-Tag: noindex');
        SpotTranslation::initialize('en_US');

        try {
            [$conn, $daoFactory, $settings, $engine] = self::connect();
        } catch (Throwable $x) {
            http_response_code(503);
            echo json_encode(['status' => 'unreachable', 'message' => 'Database unavailable']);

            return;
        } // catch

        $state = Services_Updater_Job::read($conn);
        if (!Services_Updater_Job::keyMatches($state, $key)) {
            http_response_code(403);
            echo json_encode(['status' => 'forbidden']);

            return;
        } // if

        if ($action === 'retrydb' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
                && in_array($state['status'], ['failed', 'stalled'], true) && ($state['phase'] ?? '') === 'database') {
            $state['status'] = 'files_done';
            $state['message'] = '';
            $state['updated'] = time();
            Services_Updater_Store::put($conn, Services_Updater_Job::ROW, $state);
        } // if

        if (self::canFinish($state, $settings)) {
            $state['status'] = 'database';
            self::answerThenContinue(json_encode(Services_Updater_Job::forDisplay($state)), 'application/json');
            Services_Updater_Job::finishDatabase($conn, $daoFactory, $settings, $engine);

            return;
        } // if

        echo json_encode(Services_Updater_Job::forDisplay($state));
    }

    /*
     * Called when a page finds the database out of date. When an update
     * started from Admin is waiting for its database step, this request
     * runs it and the visitor gets a "one moment" page.
     *
     * Returns false when there is no such update; the caller then shows
     * its normal message.
     */
    public static function finishFromErrorPage()
    {
        try {
            [$conn, $daoFactory, $settings, $engine] = self::connect();
            $state = Services_Updater_Job::read($conn);
        } catch (Throwable $x) {
            return false;
        } // catch

        $busy = $state !== null && ($state['status'] === 'database');
        if (!$busy && !self::canFinish($state, $settings)) {
            return false;
        } // if

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta http-equiv="refresh" content="10">'
              .'<meta name="viewport" content="width=device-width, initial-scale=1"><title>Spotweb</title>'
              .'<style>body{font-family:system-ui,sans-serif;background:#0f0f17;color:#e8e6f4;display:grid;place-items:center;min-height:100vh;margin:0}'
              .'div{max-width:28rem;padding:2rem;text-align:center}h1{font-size:1.25rem}p{color:#a7a3c2}</style></head>'
              .'<body><div><h1>Spotweb is being updated</h1><p>The database is being brought up to date. This page reloads by itself.</p>'
              .'<p lang="nl">Spotweb wordt bijgewerkt. De database wordt bijgewerkt; deze pagina laadt vanzelf opnieuw.</p></div></body></html>';
        http_response_code(503);
        header('Retry-After: 10');
        header('Cache-Control: no-store, private');
        if ($busy) {
            echo $html;

            return true;
        } // if

        self::answerThenContinue($html, 'text/html');
        Services_Updater_Job::finishDatabase($conn, $daoFactory, $settings, $engine);

        return true;
    }

    private static function canFinish(?array $state, Services_Settings_Container $settings)
    {
        if ($state === null) {
            return false;
        } // if
        if ($state['status'] === 'files_done') {
            return true;
        } // if

        // a panel deploy we asked for: finish it once its files arrived
        return $state['status'] === 'requested' && (time() - (int) $state['updated']) < 86400
            && ($settings->get('schemaversion') != SPOTDB_SCHEMA_VERSION || $settings->get('settingsversion') != SPOTWEB_SETTINGS_VERSION
                || $settings->get('securityversion') != SPOTWEB_SECURITY_VERSION);
    }

    private static function connect()
    {
        $bootstrap = new Bootstrap();
        $daoFactory = $bootstrap->getDaoFactory();
        $settings = $bootstrap->getSettings($daoFactory, false);
        $dbSettings = $bootstrap->getDbSettings();

        return [$daoFactory->getConnection(), $daoFactory, $settings, $dbSettings['engine']];
    }

    private static function answerThenContinue($body, $type)
    {
        ignore_user_abort(true);
        @set_time_limit(0);
        header('Content-Type: '.$type.'; charset=utf-8');
        header('Content-Length: '.strlen($body));
        header('Connection: close');
        echo $body;
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            while (ob_get_level() > 0) {
                ob_end_flush();
            } // while
            flush();
        } // else
    }
}
