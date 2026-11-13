<?php

/*
 * One update run, from "Update now" to the finished database upgrade.
 *
 * The state lives in the settings table (row updater_job). The browser
 * follows it with a random job key, so it can keep polling while Spotweb
 * itself is unavailable between the new files and the database upgrade.
 * Only a hash of the key is stored.
 *
 * status: running -> files_done -> database -> done
 *                 \-> failed      (any step)
 *         requested               (webhook: the hosting panel deploys)
 */
class Services_Updater_Job
{
    const ROW = 'updater_job';
    const STALE_AFTER = 1800;

    private $_conn;
    private $_state;

    public function __construct(dbeng_abs $conn)
    {
        $this->_conn = $conn;
    }

    public static function read(dbeng_abs $conn)
    {
        $state = Services_Updater_Store::get($conn, self::ROW);
        if (!is_array($state)) {
            return null;
        } // if
        if (in_array($state['status'], ['running', 'database'], true) && (time() - (int) $state['updated']) > self::STALE_AFTER) {
            $state['status'] = 'stalled';
        } // if

        return $state;
    }

    public static function isBusy(?array $state)
    {
        return $state !== null && in_array($state['status'], ['running', 'files_done', 'database'], true);
    }

    public static function keyMatches(?array $state, $key)
    {
        return $state !== null && is_string($key) && preg_match('/^[0-9a-f]{32}$/', $key)
            && hash_equals((string) ($state['keyhash'] ?? ''), hash('sha256', $key));
    }

    /*
     * The state without secrets, for the browser
     */
    public static function forDisplay(?array $state)
    {
        if ($state === null) {
            return ['status' => 'idle'];
        } // if
        unset($state['keyhash']);
        $state['busy'] = self::isBusy($state);

        return $state;
    }

    /*
     * Creates the job and returns its key
     */
    public function create(array $config, $method, array $check, $username)
    {
        $key = bin2hex(random_bytes(16));
        $installed = (new Services_Updater_Installed())->source();
        $this->_state = [
            'status'  => 'running',
            'phase'   => 'prepare',
            'method'  => $method,
            'branch'  => Services_Updater_Config::branch($config),
            'channel' => $config['channel'],
            'from'    => $installed['commit'],
            'to'      => (string) ($check['head']['sha'] ?? ''),
            'todate'  => (int) ($check['head']['date'] ?? 0),
            'by'      => (string) $username,
            'started' => time(),
            'updated' => time(),
            'message' => '',
            'result'  => null,
            'keyhash' => hash('sha256', $key),
        ];
        Services_Updater_Store::put($this->_conn, self::ROW, $this->_state);

        return $key;
    }

    /*
     * Replaces the files. Runs after the browser got its answer.
     */
    public function installFiles(array $config, array $check, $workDir)
    {
        $root = (new Services_Updater_Installed())->root();
        $branch = $this->_state['branch'];

        try {
            switch ($this->_state['method']) {
                case 'git':
                    $this->phase('git');
                    $sha = (new Services_Updater_Git($root))->install($branch);
                    $this->_state['to'] = $sha;
                    break;

                case 'webhook':
                    $this->phase('webhook');
                    $this->callWebhook($config['webhook'], $branch, $this->_state['to']);
                    $this->_state['status'] = 'requested';
                    $this->_state['phase'] = 'requested';
                    $this->save();

                    return;

                case 'archive':
                    $github = new Services_Updater_GitHub($config['repo'], $config['token']);
                    $removed = $this->removedFiles($github, $this->_state['from'], $this->_state['to']);
                    $archive = new Services_Updater_Archive($root, $workDir, function ($phase, $message) {
                        $this->phase($phase);
                    });
                    $this->_state['result'] = $archive->install($github, $this->_state['to'], $removed);
                    (new Services_Updater_Installed($root))->writeStamp($this->_state['to'], $branch, $this->_state['todate']);
                    break;

                default:
                    throw new RuntimeException('Unknown update method');
            } // switch
        } catch (Throwable $x) {
            $this->fail($x->getMessage());

            return;
        } // catch

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        } // if

        // the database upgrade runs in a fresh request, with the new code
        $this->_state['status'] = 'files_done';
        $this->_state['phase'] = 'database';
        $this->save();
    }

    /*
     * Files the new version no longer has, with their old blob sha
     */
    private function removedFiles(Services_Updater_GitHub $github, $from, $to)
    {
        if (!Services_Updater_Installed::isSha($from)) {
            return [];
        } // if
        try {
            $compare = $github->compare($from, $to);
        } catch (Exception $x) {
            return [];
        } // catch
        $removed = [];
        foreach ($compare['files'] ?? [] as $file) {
            if ($file['status'] === 'removed') {
                $removed[$file['filename']] = $file['sha'];
            } // if
        } // foreach

        return $removed;
    }

    private function callWebhook($url, $branch, $sha)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['event' => 'spotweb.update', 'branch' => $branch, 'ref' => 'refs/heads/'.$branch, 'sha' => $sha]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => 'Spotweb-updater',
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($ok === false || $status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf(_('The deploy webhook answered with %s'), $ok === false ? curl_error($ch) : 'HTTP '.$status));
        } // if
    }

    /*
     * Runs the database upgrade once the new files are in place. Safe to
     * call from several requests: only one wins the job.
     */
    public static function finishDatabase(dbeng_abs $conn, Dao_Factory $daoFactory, Services_Settings_Container $settings, $dbEngine)
    {
        $rawJson = Services_Updater_Store::getRaw($conn, self::ROW);
        $raw = $rawJson ? json_decode($rawJson, true) : null;
        if (!is_array($raw) || !in_array($raw['status'] ?? '', ['files_done', 'requested'], true)) {
            return false;
        } // if

        $job = new self($conn);
        $job->_state = $raw;
        $job->_state['status'] = 'database';
        $job->_state['updated'] = time();
        if (!Services_Updater_Store::claim($conn, self::ROW, $rawJson, $job->_state)) {
            return false;
        } // if

        ignore_user_abort(true);
        @set_time_limit(0);

        // the upgrade classes print progress for the console
        ob_start(function () { return ''; });
        try {
            $upgrade = new Services_Upgrade_Base($daoFactory, $settings, $dbEngine);
            $upgrade->database();
            $job->touch();
            $upgrade->users();
            $upgrade->settings();
            $upgrade->usenetState();
            $job->_state['status'] = 'done';
            $job->_state['phase'] = 'done';
            $job->_state['finished'] = time();
            $job->save();
        } catch (Throwable $x) {
            $job->fail(_('Database upgrade failed').': '.$x->getMessage());
        } finally {
            ob_end_clean();
        } // finally

        return true;
    }

    private function phase($phase)
    {
        $this->_state['phase'] = $phase;
        $this->save();
    }

    private function touch()
    {
        $this->save();
    }

    private function fail($message)
    {
        $this->_state['status'] = 'failed';
        $this->_state['message'] = mb_substr((string) $message, 0, 600);
        $this->save();
    }

    private function save()
    {
        $this->_state['updated'] = time();
        try {
            Services_Updater_Store::put($this->_conn, self::ROW, $this->_state);
        } catch (Exception $x) {
            // the update itself goes on
        } // catch
    }
}
