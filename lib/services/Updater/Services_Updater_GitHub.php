<?php

/*
 * Minimal GitHub REST client for the updater: branch heads, comparisons,
 * single files and source archives. Works without a token for public
 * repositories (60 calls per hour per server IP); a token raises that and
 * gives access to private repositories.
 */
class Services_Updater_GitHub
{
    const API = 'https://api.github.com';

    private $_repo;
    private $_token;
    private $_timeout;

    public function __construct($repo, $token = '', $timeout = 20)
    {
        if (!self::validRepo($repo)) {
            throw new InvalidArgumentException('Invalid repository name');
        } // if
        $this->_repo = $repo;
        $this->_token = (string) $token;
        $this->_timeout = (int) $timeout;
    }

    public static function validRepo($repo)
    {
        return is_string($repo) && (bool) preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo);
    }

    public static function validBranch($branch)
    {
        return is_string($branch) && $branch !== '' && strlen($branch) <= 100
            && (bool) preg_match('#^[A-Za-z0-9._/-]+$#', $branch) && strpos($branch, '..') === false;
    }

    /*
     * Head commit of a branch: ['sha', 'date', 'message', 'author', 'url'] or null when the branch does not exist
     */
    public function branchHead($branch)
    {
        $data = $this->api('/repos/'.$this->_repo.'/commits/'.rawurlencode($branch), [404, 422]);
        if ($data === null) {
            return null;
        } // if

        return self::commitInfo($data);
    }

    /*
     * Compares two commits; null when GitHub does not know the base
     */
    public function compare($base, $head)
    {
        $data = $this->api('/repos/'.$this->_repo.'/compare/'.rawurlencode($base).'...'.rawurlencode($head), [404]);
        if ($data === null) {
            return null;
        } // if

        $commits = [];
        foreach (array_reverse($data['commits'] ?? []) as $commit) {
            $commits[] = self::commitInfo($commit);
        } // foreach

        $files = [];
        foreach ($data['files'] ?? [] as $file) {
            $files[] = ['filename' => (string) $file['filename'], 'status' => (string) $file['status'],
                'sha' => (string) ($file['sha'] ?? ''), 'previous' => (string) ($file['previous_filename'] ?? ''), ];
        } // foreach

        return ['status' => (string) ($data['status'] ?? ''), 'ahead' => (int) ($data['ahead_by'] ?? 0),
            'behind' => (int) ($data['behind_by'] ?? 0), 'commits' => $commits, 'files' => $files,
            'files_complete' => count($files) < 300, ];
    }

    /*
     * Raw content of one file at a commit, or null
     */
    public function fileAt($path, $ref)
    {
        $data = $this->api('/repos/'.$this->_repo.'/contents/'.str_replace('%2F', '/', rawurlencode($path)).'?ref='.rawurlencode($ref), [404]);
        if (!is_array($data) || ($data['encoding'] ?? '') !== 'base64') {
            return null;
        } // if
        $raw = base64_decode((string) $data['content'], false);

        return ($raw === false) ? null : $raw;
    }

    /*
     * Downloads the zip of a commit into $target
     */
    public function downloadZip($sha, $target, $maxBytes = 268435456)
    {
        $fh = @fopen($target, 'wb');
        if ($fh === false) {
            throw new RuntimeException(sprintf('Cannot write %s', $target));
        } // if

        $ch = curl_init(self::API.'/repos/'.$this->_repo.'/zipball/'.rawurlencode($sha));
        curl_setopt_array($ch, $this->curlOptions() + [
            CURLOPT_FILE            => $fh,
            CURLOPT_TIMEOUT         => 300,
            CURLOPT_NOPROGRESS      => false,
            CURLOPT_PROGRESSFUNCTION=> function ($ch, $dlTotal, $dlNow) use ($maxBytes) {
                return ($dlNow > $maxBytes) ? 1 : 0;
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        fclose($fh);

        if ($ok === false || $status !== 200) {
            @unlink($target);
            throw new RuntimeException(sprintf('Download of the update failed (%s)', $error !== '' ? $error : 'HTTP '.$status));
        } // if
    }

    private function api($path, array $nullOn = [])
    {
        $ch = curl_init(self::API.$path);
        curl_setopt_array($ch, $this->curlOptions() + [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $this->_timeout]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);

        if ($body === false) {
            throw new RuntimeException(sprintf('Cannot reach GitHub: %s', $error));
        } // if
        if (in_array($status, $nullOn, true)) {
            return null;
        } // if
        $data = json_decode((string) $body, true);
        if ($status === 403 || $status === 429) {
            throw new RuntimeException('GitHub refused the request (rate limit). Try again later or add a GitHub token.');
        } // if
        if ($status === 401) {
            throw new RuntimeException('GitHub did not accept the token.');
        } // if
        if ($status !== 200 || !is_array($data)) {
            throw new RuntimeException(sprintf('GitHub answered with HTTP %d', $status));
        } // if

        return $data;
    }

    private function curlOptions()
    {
        $headers = ['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28'];
        if ($this->_token !== '') {
            $headers[] = 'Authorization: Bearer '.$this->_token;
        } // if

        return [
            CURLOPT_HTTPHEADER      => $headers,
            CURLOPT_USERAGENT       => 'Spotweb-updater',
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 5,
            CURLOPT_CONNECTTIMEOUT  => 10,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER  => true,
        ];
    }

    private static function commitInfo(array $data)
    {
        $message = (string) ($data['commit']['message'] ?? '');

        return [
            'sha'     => (string) ($data['sha'] ?? ''),
            'date'    => (int) strtotime((string) ($data['commit']['committer']['date'] ?? $data['commit']['author']['date'] ?? '')),
            'message' => trim(strtok($message, "\n") ?: ''),
            'author'  => (string) ($data['commit']['author']['name'] ?? ''),
        ];
    }
}
