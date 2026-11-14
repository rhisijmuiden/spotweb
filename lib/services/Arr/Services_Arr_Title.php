<?php

/*
 * Turns a spot title such as "Dune Part Two 2024 2160p UHD BluRay NL subs"
 * into the parts we need to find it in Radarr or Sonarr: the bare title,
 * the year, and for series the season and episode.
 *
 * Everything here is pure string work so it can run for a whole page of
 * spots without touching the database or the network.
 */
class Services_Arr_Title
{
    const KIND_MOVIE = 'movie';
    const KIND_SERIES = 'series';

    /*
     * Words that start the technical part of a release name. The title is
     * whatever comes before the first of these.
     */
    private static $_stopWords = [
        '480p', '540p', '576p', '720p', '1080p', '1080i', '2160p', '4320p', '4k', '8k', 'uhd', 'hd', 'fhd', 'sd',
        'bluray', 'blu', 'bdrip', 'brrip', 'bdremux', 'remux', 'web', 'webdl', 'webrip', 'hdtv', 'pdtv', 'dvdrip', 'dvd', 'dvd5', 'dvd9',
        'dvdr', 'hdrip', 'xvid', 'divx', 'x264', 'x265', 'h264', 'h265', 'hevc', 'avc', 'av1', 'hdr', 'hdr10', 'dv', 'dovi', 'sdr',
        'nf', 'amzn', 'dsnp', 'hmax', 'atvp', 'pmtp', 'nlsub', 'nlsubs', 'nlsubbed', 'subs', 'sub', 'subbed', 'ondertiteld', 'ondertiteling',
        'multi', 'dual', 'dts', 'dtshd', 'truehd', 'atmos', 'ac3', 'eac3', 'ddp', 'dd', 'dd5', 'aac', 'flac', 'mkv', 'mp4', 'avi',
        'complete', 'compleet', 'proper', 'repack', 'extended', 'remastered', 'unrated', 'uncut', 'directors', 'imax', 'limited',
        'internal', 'retail', 'custom', 'nl', 'nld', 'dutch', 'vlaams', 'flemish', 'english', 'eng', 'ned', 'nlsubtitles',
        'seizoen', 'season', 'serie', 'series', 'aflevering', 'episode', 'collection', 'trilogy', 'duology', 'boxset',
    ];

    /*
     * Which kind of media a spot is, from its category: only video spots
     * of type Movie (z0) or Series (z1) take part.
     */
    public static function kindOf(array $spot)
    {
        if ((int) ($spot['category'] ?? -1) !== 0) {
            return null;
        } // if

        $subcatz = (string) ($spot['subcatz'] ?? '');
        if (strpos($subcatz, 'z1|') !== false) {
            return self::KIND_SERIES;
        } // if
        if (strpos($subcatz, 'z0|') !== false) {
            return self::KIND_MOVIE;
        } // if

        return null;
    }

    /*
     * A comparable key for a title: lower case, no accents, only letters
     * and digits, without a leading article. "The Bear", "the-bear" and
     * "Bear" are different keys on purpose except for the article.
     */
    public static function key($title)
    {
        $title = html_entity_decode((string) $title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = mb_strtolower($title, 'UTF-8');
        $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title) : false;
        if ($ascii !== false && $ascii !== '') {
            $title = $ascii;
        } // if
        $title = str_replace('&', ' and ', $title);
        $title = preg_replace("/['`\"]/", '', $title);
        $title = preg_replace('/[^a-z0-9]+/', ' ', $title);
        $title = trim(preg_replace('/^(the|a|an|de|het|een) /', '', trim($title).' '));
        $key = str_replace(' ', '', $title);

        return substr($key, 0, 128);
    }

    /*
     * Splits a spot title. Returns
     *   title     the part before the year, season or first technical word
     *   rest      what follows it (quality and release info), without the year
     *   year      four digit year or 0
     *   season    season number or null
     *   episode   episode number or null
     *   key       key() of the title
     */
    public static function parse($spotTitle)
    {
        $raw = html_entity_decode((string) $spotTitle, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $raw = trim(preg_replace('/\s+/u', ' ', $raw));

        // turn dots and underscores in scene names into spaces, but keep "5.1"
        $clean = preg_replace('/(?<![0-9])([0-9])\.([0-9])(?![0-9])/', '$1'."\x01".'$2', $raw);
        $clean = str_replace(['.', '_', "\x01"], [' ', ' ', '.'], $clean);
        $clean = trim(preg_replace('/\s+/u', ' ', $clean));

        $result = ['title' => $clean, 'rest' => '', 'year' => 0, 'season' => null, 'episode' => null, 'key' => ''];

        // collect the positions where the title could end
        $cutBytes = strlen($clean);
        $markers = [];
        $seasonToken = '';
        $yearToken = '';

        // S03E04, S3E4, S03 E04, S03, 3x04
        if (preg_match('/(?<![a-z0-9])s(\d{1,2})[ ._-]?e(\d{1,3})(?:[-e]\d{1,3})?(?![a-z0-9])/i', $clean, $m, PREG_OFFSET_CAPTURE)) {
            $result['season'] = (int) $m[1][0];
            $result['episode'] = (int) $m[2][0];
            $markers[] = $m[0][1];
            $seasonToken = $m[0][0];
        } elseif (preg_match('/(?<![a-z0-9])(\d{1,2})x(\d{2,3})(?![a-z0-9])/i', $clean, $m, PREG_OFFSET_CAPTURE)) {
            $result['season'] = (int) $m[1][0];
            $result['episode'] = (int) $m[2][0];
            $markers[] = $m[0][1];
            $seasonToken = $m[0][0];
        } elseif (preg_match('/(?<![a-z0-9])s(\d{1,2})(?![a-z0-9])/i', $clean, $m, PREG_OFFSET_CAPTURE)) {
            $result['season'] = (int) $m[1][0];
            $markers[] = $m[0][1];
            $seasonToken = $m[0][0];
        } elseif (preg_match('/(?<![a-z0-9])(?:seizoen|season|serie)\s*(\d{1,2})(?![0-9])/i', $clean, $m, PREG_OFFSET_CAPTURE)) {
            $result['season'] = (int) $m[1][0];
            $markers[] = $m[0][1];
            $seasonToken = $m[0][0];
        } // elseif

        // a year 1900-2099; a year in brackets wins, a year at the very start is part of the title ("1917 2019"),
        // and of two years in a row the second is the release year ("Blade Runner 2049 2017")
        if (preg_match('/[\(\[]((?:19|20)\d{2})[\)\]]/', $clean, $m, PREG_OFFSET_CAPTURE) && $m[0][1] > 0) {
            $result['year'] = (int) $m[1][0];
            $yearToken = $m[0][0];
            $markers[] = $m[0][1];
        } elseif (preg_match_all('/(?<![0-9])((?:19|20)\d{2})(?![0-9])/', $clean, $years, PREG_OFFSET_CAPTURE)) {
            $found = $years[1];
            $pick = null;
            foreach ($found as $i => $match) {
                if ($match[1] === 0) {
                    continue;
                } // if
                $pick = $i;
                if (isset($found[$i + 1]) && trim(substr($clean, $match[1] + 4, $found[$i + 1][1] - $match[1] - 4)) === '') {
                    $pick = $i + 1;
                } // if
                break;
            } // foreach
            if ($pick !== null) {
                $result['year'] = (int) $found[$pick][0];
                $yearToken = $found[$pick][0];
                $markers[] = $found[$pick][1];
            } // if
        } // elseif

        // the first technical word or a bracket
        if (preg_match_all('/[\p{L}\p{N}]+|[\(\[\{]/u', $clean, $words, PREG_OFFSET_CAPTURE)) {
            foreach ($words[0] as $i => $word) {
                if ($i === 0) {
                    continue;
                } // if
                $w = mb_strtolower($word[0], 'UTF-8');
                if ($w === '(' || $w === '[' || $w === '{' || in_array($w, self::$_stopWords, true)) {
                    $markers[] = $word[1];
                    break;
                } // if
            } // foreach
        } // if

        if (!empty($markers)) {
            $cutBytes = min($markers);
        } // if

        $title = trim(substr($clean, 0, $cutBytes), " \t-–:|,");
        $rest = trim(substr($clean, $cutBytes));
        if ($yearToken !== '' && ($pos = strpos($rest, $yearToken)) !== false) {
            $rest = substr($rest, 0, $pos).substr($rest, $pos + strlen($yearToken));
        } // if
        if ($seasonToken !== '' && ($pos = strpos($rest, $seasonToken)) !== false) {
            $rest = substr($rest, 0, $pos).substr($rest, $pos + strlen($seasonToken));
        } // if
        $rest = trim(preg_replace('/\s+/u', ' ', $rest), " \t-–:|,");

        if ($title === '') {
            $title = $clean;
            $rest = '';
        } // if

        $result['title'] = $title;
        $result['rest'] = $rest;
        $result['key'] = self::key($title);

        return $result;
    }

    /*
     * The job name we give SABnzbd: the title as Radarr or Sonarr know it,
     * with the year for movies, followed by the release info of the spot.
     * Radarr and Sonarr read this name when they import the download.
     */
    public static function downloadName(array $parsed, $kind, $libraryTitle = '', $libraryYear = 0)
    {
        $title = ($libraryTitle !== '') ? $libraryTitle : $parsed['title'];
        $year = ((int) $libraryYear > 0) ? (int) $libraryYear : (int) $parsed['year'];
        $rest = (string) $parsed['rest'];

        if ($kind === self::KIND_MOVIE) {
            $name = $title.($year > 0 ? ' ('.$year.')' : '');
        } else {
            $name = $title;
            if ($parsed['season'] !== null) {
                $name .= ' S'.sprintf('%02d', $parsed['season']);
                if ($parsed['episode'] !== null) {
                    $name .= 'E'.sprintf('%02d', $parsed['episode']);
                } // if
            } // if
        } // else

        $name = trim($name.' '.$rest);
        // characters that are not allowed in folder names on common file systems
        $name = preg_replace('/[\\\\\/:*?"<>|]+/', ' ', $name);

        return trim(preg_replace('/\s+/u', ' ', $name));
    }
}
