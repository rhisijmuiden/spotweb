<?php

/*
 * What is installed right now: the Spotweb version numbers and, when we
 * can tell, the git branch and commit the code came from.
 *
 * The commit is read without running git (production servers often have
 * exec() disabled): from .git when the folder is a git checkout, and from
 * the stamp file the updater writes after an archive update. When both
 * exist, the newest one wins.
 */
class Services_Updater_Installed
{
    const STAMP_FILE = '.spotweb-release.json';

    private $_root;

    public function __construct($root = null)
    {
        $this->_root = rtrim($root ?? dirname(__DIR__, 3), '/\\');
    }

    public function root()
    {
        return $this->_root;
    }

    /*
     * Everything the About page shows
     */
    public function info()
    {
        $source = $this->source();

        return [
            'version'         => SPOTWEB_VERSION,
            'schema'          => SPOTDB_SCHEMA_VERSION,
            'settings'        => SPOTWEB_SETTINGS_VERSION,
            'security'        => SPOTWEB_SECURITY_VERSION,
            'php'             => PHP_VERSION,
            'commit'          => $source['commit'],
            'short'           => $source['commit'] ? substr($source['commit'], 0, 7) : '',
            'branch'          => $source['branch'],
            'date'            => $source['date'],
            'from'            => $source['from'],
        ];
    }

    /*
     * Where the code came from: ['commit', 'branch', 'date', 'from'],
     * from = 'git', 'stamp' or '' when unknown.
     */
    public function source()
    {
        $git = $this->readGit();
        $stamp = $this->readStamp();

        if ($git !== null && $stamp !== null) {
            // whichever was written last describes the files on disk
            return ($stamp['time'] >= $git['time']) ? $stamp : $git;
        } // if

        return $git ?? $stamp ?? ['commit' => '', 'branch' => '', 'date' => 0, 'from' => '', 'time' => 0];
    }

    public function writeStamp($commit, $branch, $date)
    {
        $data = ['commit' => (string) $commit, 'branch' => (string) $branch, 'date' => (int) $date, 'installed' => time()];

        return @file_put_contents($this->_root.'/'.self::STAMP_FILE, json_encode($data, JSON_PRETTY_PRINT)."\n") !== false;
    }

    private function readStamp()
    {
        $file = $this->_root.'/'.self::STAMP_FILE;
        if (!is_file($file)) {
            return null;
        } // if
        $data = json_decode((string) @file_get_contents($file), true);
        if (!is_array($data) || !self::isSha($data['commit'] ?? '')) {
            return null;
        } // if

        return ['commit' => strtolower($data['commit']), 'branch' => (string) ($data['branch'] ?? ''),
            'date' => (int) ($data['date'] ?? 0), 'from' => 'stamp', 'time' => (int) @filemtime($file), ];
    }

    /*
     * Reads HEAD from the .git folder (or the gitdir a .git file points to)
     */
    private function readGit()
    {
        $gitDir = $this->gitDir();
        if ($gitDir === null) {
            return null;
        } // if

        $head = trim((string) @file_get_contents($gitDir.'/HEAD'));
        $branch = '';
        $commit = '';
        if (strpos($head, 'ref:') === 0) {
            $ref = trim(substr($head, 4));
            if (strpos($ref, 'refs/heads/') === 0) {
                $branch = substr($ref, strlen('refs/heads/'));
            } // if
            $commit = $this->resolveRef($gitDir, $ref);
        } elseif (self::isSha($head)) {
            $commit = strtolower($head);
        } // else

        if ($commit === '') {
            return null;
        } // if

        // the index changes on every checkout, pull or reset
        $time = max((int) @filemtime($gitDir.'/HEAD'), (int) @filemtime($gitDir.'/index'));

        return ['commit' => $commit, 'branch' => $branch, 'date' => 0, 'from' => 'git', 'time' => $time];
    }

    public function gitDir()
    {
        $dotGit = $this->_root.'/.git';
        if (is_dir($dotGit) && is_file($dotGit.'/HEAD')) {
            return $dotGit;
        } // if
        if (is_file($dotGit)) {
            // worktrees and submodules: "gitdir: <path>"
            $line = trim((string) @file_get_contents($dotGit));
            if (strpos($line, 'gitdir:') === 0) {
                $path = trim(substr($line, 7));
                if ($path !== '' && $path[0] !== '/' && !preg_match('#^[A-Za-z]:[\\\\/]#', $path)) {
                    $path = $this->_root.'/'.$path;
                } // if
                if (is_file($path.'/HEAD')) {
                    return $path;
                } // if
            } // if
        } // if

        return null;
    }

    private function resolveRef($gitDir, $ref)
    {
        // loose ref first, then packed-refs; worktrees keep shared refs in commondir
        $dirs = [$gitDir];
        if (is_file($gitDir.'/commondir')) {
            $common = trim((string) @file_get_contents($gitDir.'/commondir'));
            $dirs[] = ($common !== '' && $common[0] === '/') ? $common : $gitDir.'/'.$common;
        } // if

        foreach ($dirs as $dir) {
            $loose = trim((string) @file_get_contents($dir.'/'.$ref));
            if (self::isSha($loose)) {
                return strtolower($loose);
            } // if
        } // foreach

        foreach ($dirs as $dir) {
            $packed = @file($dir.'/packed-refs', FILE_IGNORE_NEW_LINES);
            if (!is_array($packed)) {
                continue;
            } // if
            foreach ($packed as $line) {
                $parts = explode(' ', trim($line), 2);
                if (count($parts) === 2 && $parts[1] === $ref && self::isSha($parts[0])) {
                    return strtolower($parts[0]);
                } // if
            } // foreach
        } // foreach

        return '';
    }

    public static function isSha($value)
    {
        return is_string($value) && (bool) preg_match('/^[0-9a-f]{40}$/i', $value);
    }

    /*
     * The git blob id of a file, to compare local files with GitHub
     */
    public static function blobSha($file)
    {
        $data = @file_get_contents($file);
        if ($data === false) {
            return null;
        } // if

        return sha1('blob '.strlen($data)."\0".$data);
    }
}
