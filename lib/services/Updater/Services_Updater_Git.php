<?php

/*
 * Updates a git checkout by running git itself. Only used when PHP may
 * start processes (proc_open) and the folder is a clean checkout.
 */
class Services_Updater_Git
{
    private $_root;

    public function __construct($root)
    {
        $this->_root = rtrim($root, '/\\');
    }

    /*
     * Returns '' when git can be used, or the reason why not
     */
    public function unavailableReason()
    {
        if (!function_exists('proc_open') || in_array('proc_open', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
            return _('PHP may not start programs on this server (proc_open is disabled).');
        } // if
        if ((new Services_Updater_Installed($this->_root))->gitDir() === null) {
            return _('This installation is not a git checkout.');
        } // if
        try {
            $this->git(['--version']);
        } catch (Exception $x) {
            return _('git is not installed or cannot be run.');
        } // catch

        return '';
    }

    public function install($branch)
    {
        if (!Services_Updater_GitHub::validBranch($branch)) {
            throw new InvalidArgumentException('Invalid branch');
        } // if

        $dirty = trim($this->git(['status', '--porcelain', '--untracked-files=no']));
        if ($dirty !== '') {
            throw new RuntimeException(_('The installation has local changes to tracked files, so git will not update it. Commit or undo them first.').' '.mb_substr($dirty, 0, 300));
        } // if

        $this->git(['fetch', '--quiet', 'origin', '+refs/heads/'.$branch.':refs/remotes/origin/'.$branch]);

        $current = trim($this->git(['rev-parse', '--abbrev-ref', 'HEAD']));
        if ($current !== $branch) {
            $hasLocal = $this->gitOk(['show-ref', '--verify', '--quiet', 'refs/heads/'.$branch]);
            if ($hasLocal) {
                $this->git(['checkout', '--quiet', $branch]);
            } else {
                $this->git(['checkout', '--quiet', '-b', $branch, '--track', 'origin/'.$branch]);
            } // else
        } // if

        $this->git(['merge', '--ff-only', '--quiet', 'origin/'.$branch]);

        return trim($this->git(['rev-parse', 'HEAD']));
    }

    private function gitOk(array $args)
    {
        try {
            $this->git($args);

            return true;
        } catch (Exception $x) {
            return false;
        } // catch
    }

    private function git(array $args)
    {
        $env = getenv();
        $env['GIT_TERMINAL_PROMPT'] = '0';
        $env['LC_ALL'] = 'C';
        $proc = @proc_open(array_merge(['git', '-C', $this->_root], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->_root, $env);
        if (!is_resource($proc)) {
            throw new RuntimeException('Cannot start git');
        } // if
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0) {
            throw new RuntimeException('git '.$args[0].': '.trim($err !== '' ? $err : $out));
        } // if

        return (string) $out;
    }
}
