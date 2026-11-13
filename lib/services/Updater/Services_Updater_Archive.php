<?php

/*
 * Installs a commit from its GitHub zip, without git or a shell.
 *
 *  1. download the zip into the work folder;
 *  2. unpack it into a staging folder and check it is Spotweb;
 *  3. work out which files change and check they are all writable,
 *     before touching anything;
 *  4. copy a backup of every file that will be replaced or removed;
 *  5. replace the files one by one (write + rename); on any error the
 *     backup is put back and new files are removed again.
 *
 * Settings files and data folders are never touched.
 */
class Services_Updater_Archive
{
    const PROTECTED_PATHS = ['dbsettings.inc.php', 'ownsettings.php', 'reallymyownsettings.php', 'server-settings.php',
        '.htpasswd', 'web.config', 'retrieve.sh', Services_Updater_Installed::STAMP_FILE, ];
    const PROTECTED_DIRS = ['.git', 'cache', 'logs', 'spotweb-config', 'data'];

    private $_root;
    private $_work;
    private $_progress;

    /*
     * $progress is called as fn($phase, $message)
     */
    public function __construct($root, $workDir, ?callable $progress = null)
    {
        $this->_root = rtrim($root, '/\\');
        $this->_work = rtrim($workDir, '/\\');
        $this->_progress = $progress ?? function () {};
    }

    public static function available()
    {
        return class_exists('ZipArchive') && function_exists('curl_init');
    }

    /*
     * $removed: paths (relative) that the new version no longer has, with
     * the blob sha of the old content; they are only removed when the
     * local file is unchanged.
     *
     * Returns ['changed' => n, 'added' => n, 'removed' => n]
     */
    public function install(Services_Updater_GitHub $github, $sha, array $removed = [])
    {
        $this->prepareWorkDir();
        $zip = $this->_work.'/download.zip';
        $staging = $this->_work.'/staging';
        self::removeTree($staging);

        ($this->_progress)('download', '');
        $github->downloadZip($sha, $zip);

        ($this->_progress)('extract', '');
        $this->extract($zip, $staging);
        @unlink($zip);

        return $this->installFrom($staging, $removed);
    }

    /*
     * The copy part on its own, also used by the tests
     */
    public function installFrom($staging, array $removed = [])
    {
        foreach (['index.php', 'lib/Bootstrap.php', 'vendor/autoload.php'] as $must) {
            if (!is_file($staging.'/'.$must)) {
                throw new RuntimeException(sprintf('The download is not a complete Spotweb (%s is missing)', $must));
            } // if
        } // foreach

        ($this->_progress)('plan', '');
        $plan = $this->plan($staging, $removed);

        $blocked = $this->unwritable($plan);
        if (!empty($blocked)) {
            throw new RuntimeException(sprintf('The webserver cannot write these files, nothing was changed: %s', implode(', ', array_slice($blocked, 0, 8)).(count($blocked) > 8 ? ', …' : '')));
        } // if

        ($this->_progress)('backup', '');
        $backup = $this->_work.'/backup';
        self::removeTree($backup);
        $this->backup($plan, $backup);

        ($this->_progress)('copy', '');
        $created = [];
        try {
            foreach ($plan['write'] as $rel) {
                $existed = file_exists($this->_root.'/'.$rel);
                $this->writeFile($staging.'/'.$rel, $this->_root.'/'.$rel);
                if (!$existed) {
                    $created[] = $rel;
                } // if
            } // foreach
            foreach ($plan['remove'] as $rel) {
                if (!@unlink($this->_root.'/'.$rel)) {
                    throw new RuntimeException(sprintf('Could not remove %s', $rel));
                } // if
            } // foreach
        } catch (Exception $x) {
            ($this->_progress)('rollback', $x->getMessage());
            $this->restore($plan, $backup, $created);

            throw new RuntimeException($x->getMessage().' — the previous files have been put back.', 0, $x);
        } // catch

        self::invalidateOpcache(array_merge($plan['write'], $plan['remove']), $this->_root);
        self::removeTree($staging);

        return ['changed' => count($plan['write']) - count($plan['new']), 'added' => count($plan['new']), 'removed' => count($plan['remove'])];
    }

    private function prepareWorkDir()
    {
        if (!is_dir($this->_work) && !@mkdir($this->_work, 0750, true)) {
            throw new RuntimeException(sprintf('Cannot create the work folder %s', $this->_work));
        } // if
        if (!is_writable($this->_work)) {
            throw new RuntimeException(sprintf('The work folder %s is not writable', $this->_work));
        } // if
        // keep the webserver out, should the folder be reachable
        @file_put_contents($this->_work.'/.htaccess', "Require all denied\nDeny from all\n");
    }

    /*
     * Unpacks the zip without trusting its paths. GitHub puts everything
     * in one top folder (owner-repo-sha/), which we strip.
     */
    public function extract($zipFile, $target)
    {
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new RuntimeException('The downloaded update is not a valid zip file');
        } // if

        try {
            $prefix = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                $slash = strpos($name, '/');
                $top = ($slash === false) ? $name : substr($name, 0, $slash + 1);
                if ($prefix === null) {
                    $prefix = $top;
                } elseif ($prefix !== $top) {
                    $prefix = '';
                    break;
                } // elseif
            } // for
            $prefix = (string) $prefix;
            if (substr($prefix, -1) !== '/') {
                $prefix = '';
            } // if

            if (!@mkdir($target, 0750, true) && !is_dir($target)) {
                throw new RuntimeException('Cannot create the staging folder');
            } // if

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                $rel = substr($name, strlen($prefix));
                if ($rel === '' || $rel === false) {
                    continue;
                } // if
                if (!self::safeRelative(rtrim($rel, '/'))) {
                    throw new RuntimeException(sprintf('Unsafe path in the update: %s', $name));
                } // if
                $dest = $target.'/'.$rel;
                if (substr($rel, -1) === '/') {
                    if (!is_dir($dest) && !@mkdir($dest, 0755, true)) {
                        throw new RuntimeException(sprintf('Cannot create %s', $rel));
                    } // if
                    continue;
                } // if

                // symlinks in the archive are skipped; Spotweb has none
                $attr = 0;
                $opsys = 0;
                if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) {
                    continue;
                } // if

                $dir = dirname($dest);
                if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
                    throw new RuntimeException(sprintf('Cannot create %s', dirname($rel)));
                } // if
                $data = $zip->getFromIndex($i);
                if ($data === false || @file_put_contents($dest, $data) === false) {
                    throw new RuntimeException(sprintf('Cannot unpack %s', $rel));
                } // if
            } // for
        } finally {
            $zip->close();
        } // finally
    }

    /*
     * Which files to write (new or different) and which to remove
     */
    public function plan($staging, array $removed)
    {
        $write = [];
        $new = [];
        foreach (self::listFiles($staging) as $rel) {
            if (self::isProtected($rel)) {
                continue;
            } // if
            $target = $this->_root.'/'.$rel;
            if (!file_exists($target)) {
                $write[] = $rel;
                $new[] = $rel;
            } elseif (filesize($target) !== filesize($staging.'/'.$rel) || sha1_file($target) !== sha1_file($staging.'/'.$rel)) {
                $write[] = $rel;
            } // elseif
        } // foreach

        $remove = [];
        foreach ($removed as $rel => $oldSha) {
            if (!self::safeRelative($rel) || self::isProtected($rel) || file_exists($staging.'/'.$rel)) {
                continue;
            } // if
            $target = $this->_root.'/'.$rel;
            if (is_file($target) && Services_Updater_Installed::blobSha($target) === $oldSha) {
                $remove[] = $rel;
            } // if
        } // foreach

        return ['write' => $write, 'new' => $new, 'remove' => $remove];
    }

    private function unwritable(array $plan)
    {
        $blocked = [];
        foreach (array_merge($plan['write'], $plan['remove']) as $rel) {
            $target = $this->_root.'/'.$rel;
            if (file_exists($target)) {
                // replacing goes through rename(), which needs the folder
                if (!is_writable(dirname($target))) {
                    $blocked[] = $rel;
                } // if
                continue;
            } // if
            $dir = dirname($target);
            while (!file_exists($dir) && strlen($dir) > strlen($this->_root)) {
                $dir = dirname($dir);
            } // while
            if (!is_dir($dir) || !is_writable($dir)) {
                $blocked[] = $rel;
            } // if
        } // foreach

        return $blocked;
    }

    private function backup(array $plan, $backup)
    {
        $existing = array_merge(array_diff($plan['write'], $plan['new']), $plan['remove']);
        foreach ($existing as $rel) {
            $dest = $backup.'/'.$rel;
            if (!is_dir(dirname($dest)) && !@mkdir(dirname($dest), 0750, true)) {
                throw new RuntimeException('Cannot write the backup of the current files');
            } // if
            if (!@copy($this->_root.'/'.$rel, $dest)) {
                throw new RuntimeException(sprintf('Cannot back up %s', $rel));
            } // if
        } // foreach
    }

    private function restore(array $plan, $backup, array $created)
    {
        foreach (array_merge(array_diff($plan['write'], $plan['new']), $plan['remove']) as $rel) {
            if (is_file($backup.'/'.$rel)) {
                @copy($backup.'/'.$rel, $this->_root.'/'.$rel);
            } // if
        } // foreach
        foreach ($created as $rel) {
            @unlink($this->_root.'/'.$rel);
        } // foreach
        self::invalidateOpcache(array_merge($plan['write'], $plan['remove']), $this->_root);
    }

    private function writeFile($source, $target)
    {
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            throw new RuntimeException(sprintf('Cannot create folder %s', $dir));
        } // if
        $tmp = $target.'.swupdate-'.bin2hex(random_bytes(4));
        if (!@copy($source, $tmp)) {
            throw new RuntimeException(sprintf('Cannot write %s', substr($target, strlen($this->_root) + 1)));
        } // if
        if (file_exists($target)) {
            @chmod($tmp, fileperms($target) & 0777);
        } // if
        if (!@rename($tmp, $target)) {
            @unlink($tmp);

            throw new RuntimeException(sprintf('Cannot replace %s', substr($target, strlen($this->_root) + 1)));
        } // if
    }

    public static function isProtected($rel)
    {
        if (in_array($rel, self::PROTECTED_PATHS, true)) {
            return true;
        } // if
        $first = explode('/', $rel, 2)[0];

        return in_array($first, self::PROTECTED_DIRS, true);
    }

    public static function safeRelative($rel)
    {
        if (!is_string($rel) || $rel === '' || $rel[0] === '/' || strpos($rel, '\\') !== false || strpos($rel, "\0") !== false) {
            return false;
        } // if
        foreach (explode('/', $rel) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return false;
            } // if
        } // foreach

        return true;
    }

    public static function listFiles($dir, $prefix = '')
    {
        $out = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            } // if
            $path = $dir.'/'.$entry;
            if (is_link($path)) {
                continue;
            } // if
            if (is_dir($path)) {
                $out = array_merge($out, self::listFiles($path, $prefix.$entry.'/'));
            } else {
                $out[] = $prefix.$entry;
            } // else
        } // foreach

        return $out;
    }

    public static function removeTree($path)
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        } // if
        if (!is_dir($path)) {
            return;
        } // if
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path.'/'.$entry);
            } // if
        } // foreach
        @rmdir($path);
    }

    public static function invalidateOpcache(array $files, $root)
    {
        if (!function_exists('opcache_invalidate')) {
            return;
        } // if
        foreach ($files as $rel) {
            if (substr($rel, -4) === '.php') {
                @opcache_invalidate($root.'/'.$rel, true);
            } // if
        } // foreach
    }
}
