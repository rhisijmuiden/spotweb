<?php

/*
 * Entry point for <spotweb>/api/ so Newznab clients (Prowlarr, Sonarr,
 * Radarr, ...) work without any webserver rewrite rule. A request for
 * <spotweb>/api is redirected here by the webserver as a directory.
 */
$_GET['page'] = 'newznabapi';
chdir(dirname(__DIR__));

require dirname(__DIR__).'/index.php';
