<?php

/*
 * Shares how far a retrieve is, so Admin > Retrieve can show a progress
 * bar. Works for retrieves started from the admin centre and for the
 * normal cron / timer runs of retrieve.php.
 *
 * The state is a small JSON document in the settings table, written at
 * most once per second while a retrieve runs.
 */
class Services_Retriever_Progress
{
    const SETTING = 'retrieve_progress';
    const STALE_AFTER = 900; // seconds without an update before a run counts as dead

    /** @var dbeng_abs|null */
    private static $_conn = null;
    private static $_state = null;
    private static $_lastWrite = 0.0;

    public static function attach(dbeng_abs $conn)
    {
        self::$_conn = $conn;
    }

    public static function start($trigger, $mode)
    {
        self::$_state = [
            'status'    => 'running',
            'trigger'   => $trigger,      // 'admin' or 'cron'
            'mode'      => $mode,         // 'normal' or 'backfill'
            'phase'     => 'connecting',
            'started'   => time(),
            'updated'   => time(),
            'current'   => 0,
            'first'     => 0,
            'last'      => 0,
            'processed' => 0,
            'totals'    => ['spots' => 0, 'comments' => 0, 'reports' => 0],
            'message'   => '',
        ];
        self::write(true);
    }

    public static function phase($phase)
    {
        if (self::$_state === null) {
            return;
        } // if
        self::$_state['phase'] = $phase;
        self::$_state['current'] = 0;
        self::$_state['first'] = 0;
        self::$_state['last'] = 0;
        self::$_state['processed'] = 0;
        self::write(true);
    }

    /*
     * Called after every batch of headers
     */
    public static function report($phase, $current, $first, $last, $processed)
    {
        if (self::$_state === null) {
            return;
        } // if
        self::$_state['phase'] = $phase;
        self::$_state['current'] = (int) $current;
        self::$_state['first'] = (int) $first;
        self::$_state['last'] = (int) $last;
        self::$_state['processed'] = (int) $processed;
        self::write(false);
    }

    public static function addTotal($kind, $count)
    {
        if (self::$_state === null) {
            return;
        } // if
        self::$_state['totals'][$kind] = (int) $count;
    }

    public static function finish()
    {
        if (self::$_state === null) {
            return;
        } // if
        self::$_state['status'] = 'done';
        self::$_state['phase'] = 'done';
        self::write(true);
        self::$_state = null;
    }

    public static function fail($message)
    {
        if (self::$_state === null) {
            return;
        } // if
        self::$_state['status'] = 'failed';
        self::$_state['message'] = mb_substr((string) $message, 0, 300);
        self::write(true);
        self::$_state = null;
    }

    private static function write($force)
    {
        if (self::$_conn === null) {
            return;
        } // if
        $now = microtime(true);
        if (!$force && ($now - self::$_lastWrite) < 1.0) {
            return;
        } // if
        self::$_lastWrite = $now;
        self::$_state['updated'] = time();

        try {
            $json = json_encode(self::$_state);
            $params = [':name' => [self::SETTING, PDO::PARAM_STR], ':value' => [$json, PDO::PARAM_STR]];
            $stmt = self::$_conn->exec('UPDATE settings SET value = :value, serialized = 0 WHERE name = :name', $params);
            $updated = $stmt->rowCount();
            $stmt->closeCursor();
            if ($updated === 0) {
                $exists = self::$_conn->singleQuery('SELECT 1 FROM settings WHERE name = :name', [':name' => [self::SETTING, PDO::PARAM_STR]]);
                if (!$exists) {
                    self::$_conn->modify('INSERT INTO settings(name, value, serialized) VALUES(:name, :value, 0)', $params);
                } // if
            } // if
        } catch (Exception $x) {
            // progress is informational only
        } // catch
    }

    /*
     * The latest state, with a percentage and a running flag for the UI
     */
    public static function read(dbeng_abs $conn)
    {
        $json = $conn->singleQuery('SELECT value FROM settings WHERE name = :name', [':name' => [self::SETTING, PDO::PARAM_STR]]);
        $state = $json ? json_decode($json, true) : null;
        if (!is_array($state)) {
            return ['status' => 'idle', 'running' => false, 'percent' => 0];
        } // if

        if (($state['status'] === 'running') && ((time() - (int) $state['updated']) > self::STALE_AFTER)) {
            $state['status'] = 'stalled';
        } // if
        $state['running'] = ($state['status'] === 'running');

        $span = (int) $state['last'] - (int) $state['first'];
        $state['percent'] = ($span > 0) ? max(0, min(100, round(100 * ((int) $state['current'] - (int) $state['first']) / $span, 1))) : 0;
        if ($state['status'] === 'done') {
            $state['percent'] = 100;
        } // if

        return $state;
    }
}
