<?php

/*
 * Compares what is installed with the head of the chosen branch on GitHub.
 *
 * States:
 *   uptodate  - nothing to do
 *   behind    - newer commits are available
 *   switch    - the other channel is chosen; installing it switches branch
 *   unknown   - we cannot tell which commit is installed; installing the
 *               branch head is safe
 *   nobranch  - the branch does not exist on GitHub (yet)
 *   error     - GitHub could not be reached
 *
 * 'blocked' is set when installing would lower the database schema or
 * settings version; Spotweb cannot go back on its database layout.
 */
class Services_Updater_Check
{
    const ROW = 'updater_check';
    const MAX_COMMITS = 30;

    private $_conn;
    private $_installed;

    public function __construct(dbeng_abs $conn, ?Services_Updater_Installed $installed = null)
    {
        $this->_conn = $conn;
        $this->_installed = $installed ?? new Services_Updater_Installed();
    }

    public function cached()
    {
        return Services_Updater_Store::get($this->_conn, self::ROW);
    }

    public function run(array $config, ?Services_Updater_GitHub $github = null)
    {
        $branch = Services_Updater_Config::branch($config);
        $result = ['checked' => time(), 'branch' => $branch, 'channel' => $config['channel'], 'repo' => $config['repo'],
            'state' => 'error', 'head' => null, 'ahead' => 0, 'behind' => 0, 'commits' => [], 'more' => 0,
            'blocked' => '', 'error' => '', 'detected' => false, ];

        try {
            $github = $github ?? new Services_Updater_GitHub($config['repo'], $config['token']);
            $result = $this->compare($github, $branch, $result);
        } catch (Exception $x) {
            $result['state'] = 'error';
            $result['error'] = $x->getMessage();
        } // catch

        try {
            Services_Updater_Store::put($this->_conn, self::ROW, $result);
        } catch (Exception $x) {
            // the check result is only a cache
        } // catch

        return $result;
    }

    private function compare(Services_Updater_GitHub $github, $branch, array $result)
    {
        $head = $github->branchHead($branch);
        if ($head === null) {
            $result['state'] = 'nobranch';

            return $result;
        } // if
        $result['head'] = $head;

        $source = $this->_installed->source();
        $switching = ($source['branch'] !== '' && $source['branch'] !== $branch);

        if ($source['commit'] === $head['sha']) {
            $result['state'] = 'uptodate';
            if ($switching) {
                // same commit on both branches: only the label changes
                $this->_installed->writeStamp($head['sha'], $branch, $head['date']);
            } // if

            return $result;
        } // if

        $compare = ($source['commit'] !== '') ? $github->compare($source['commit'], $head['sha']) : null;
        if ($compare === null) {
            $result['state'] = 'unknown';
        } else {
            $result['ahead'] = $compare['ahead'];
            $result['behind'] = $compare['behind'];
            $result['commits'] = array_slice($compare['commits'], 0, self::MAX_COMMITS);
            $result['more'] = max(0, $compare['ahead'] - self::MAX_COMMITS);

            if ($compare['ahead'] === 0 && $compare['behind'] === 0) {
                $result['state'] = 'uptodate';
            } elseif ($switching) {
                $result['state'] = 'switch';
            } else {
                $result['state'] = 'behind';
            } // else

            // deployed by something else (a hosting panel) after our last stamp?
            if ($result['state'] !== 'uptodate' && $this->filesMatch($compare)) {
                $this->_installed->writeStamp($head['sha'], $branch, $head['date']);
                $result['state'] = 'uptodate';
                $result['detected'] = true;
                $result['ahead'] = $result['behind'] = 0;
                $result['commits'] = [];

                return $result;
            } // if
        } // else

        if ($result['state'] !== 'uptodate') {
            $result['blocked'] = $this->downgradeReason($github, $head['sha']);
        } // if

        return $result;
    }

    /*
     * True when every file the comparison lists already has the new content
     */
    public function filesMatch(array $compare)
    {
        if (empty($compare['files']) || !$compare['files_complete']) {
            return false;
        } // if

        $root = $this->_installed->root();
        foreach ($compare['files'] as $file) {
            $path = $root.'/'.$file['filename'];
            if ($file['status'] === 'removed') {
                if (file_exists($path)) {
                    return false;
                } // if
                continue;
            } // if
            if ($file['sha'] === '' || Services_Updater_Installed::blobSha($path) !== $file['sha']) {
                return false;
            } // if
        } // foreach

        return true;
    }

    private function downgradeReason(Services_Updater_GitHub $github, $sha)
    {
        $bootstrap = $github->fileAt('lib/Bootstrap.php', $sha);
        if ($bootstrap === null) {
            return _('The update does not look like Spotweb (lib/Bootstrap.php is missing).');
        } // if

        $target = self::versionsFromBootstrap($bootstrap);
        $installed = ['schema' => SPOTDB_SCHEMA_VERSION, 'settings' => SPOTWEB_SETTINGS_VERSION, 'security' => SPOTWEB_SECURITY_VERSION];
        foreach ($installed as $key => $value) {
            if (!isset($target[$key])) {
                return _('Could not read the version numbers of the update.');
            } // if
            if ((float) $target[$key] < (float) $value) {
                return sprintf(_('This version has an older database layout (%s %s, installed %s). Spotweb cannot go back to an older layout, so wait until this branch has caught up.'), $key, $target[$key], $value);
            } // if
        } // foreach

        return '';
    }

    /*
     * Reads the define()s for the schema, settings and security version
     */
    public static function versionsFromBootstrap($source)
    {
        $map = ['SPOTDB_SCHEMA_VERSION' => 'schema', 'SPOTWEB_SETTINGS_VERSION' => 'settings', 'SPOTWEB_SECURITY_VERSION' => 'security'];
        $found = [];
        foreach ($map as $const => $key) {
            if (preg_match("/define\\(\\s*'".$const."'\\s*,\\s*'([0-9.]+)'\\s*\\)/", $source, $m)) {
                $found[$key] = $m[1];
            } // if
        } // foreach

        return $found;
    }
}
