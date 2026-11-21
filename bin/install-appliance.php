#!/usr/bin/php
<?php

/*
 * Installs Spotweb without the web wizard, for appliances such as the
 * Proxmox LXC template (contrib/proxmox-lxc).
 *
 * The database settings file (dbsettings.inc.php) must already exist and
 * point to an empty database. This script then does what install.php does:
 * it creates the tables, the default settings and security groups, and an
 * administrator account.
 *
 *   SPOTWEB_ADMIN_PASSWORD=SECRET php bin/install-appliance.php \
 *       [--username=spotadmin] [--mail=admin@example.com] [--systemtype=shared|public|single]
 *
 * (--password=SECRET works too, but then the password shows up in the
 * process list.)
 *
 * The usenet server is configured afterwards in Admin > Settings.
 */

error_reporting(2147483647);

require_once __DIR__.'/../vendor/autoload.php';

SpotCommandline::initialize(
    [],
    ['username' => 'spotadmin', 'password' => false, 'mail' => 'admin@spotweb.lan', 'systemtype' => 'shared',
        'firstname' => 'Spotweb', 'lastname' => 'Admin', ]
);
if (!SpotCommandline::isCommandline()) {
    exit('install-appliance.php can only be run from the console');
}

function fail($message)
{
    fwrite(STDERR, 'install-appliance: '.$message.PHP_EOL);
    exit(1);
}

$password = SpotCommandline::get('password');
if (!is_string($password) || $password === '') {
    $password = getenv('SPOTWEB_ADMIN_PASSWORD');
}
if (!is_string($password) || strlen($password) < 8) {
    fail('set SPOTWEB_ADMIN_PASSWORD (or --password=) to at least 8 characters');
}
$systemType = SpotCommandline::get('systemtype');
if (!in_array($systemType, ['single', 'shared', 'public'], true)) {
    fail('--systemtype must be single, shared or public');
}

try {
    $bootstrap = new Bootstrap();
    $daoFactory = $bootstrap->getDaoFactory();
    $dbSettings = $bootstrap->getDbSettings();
    $dbCon = $daoFactory->getConnection();

    // Refuse to touch a database that already holds a Spotweb installation.
    try {
        $existingUsers = (int) $dbCon->singleQuery('SELECT COUNT(*) FROM users');
    } catch (Exception $x) {
        $existingUsers = 0; // no users table yet
    }
    if ($existingUsers > 0) {
        fail('this database already contains a Spotweb installation, nothing changed');
    }

    // Tables, default settings, security groups and the built-in users.
    ob_start();
    $dbStruct = SpotStruct_abs::factory($dbSettings['engine'], $dbCon);
    $dbStruct->updateSchema();
    $spotSettings = $bootstrap->getSettings($daoFactory, false);
    $svcUpgradeBase = new Services_Upgrade_Base($daoFactory, $spotSettings, $dbSettings['engine']);
    $svcUpgradeBase->settings();
    $svcUpgradeBase->users();
    ob_end_clean();

    // The administrator, created the same way as by install.php.
    $spotUser = [
        'username'     => SpotCommandline::get('username'),
        'newpassword1' => $password,
        'newpassword2' => $password,
        'firstname'    => SpotCommandline::get('firstname'),
        'lastname'     => SpotCommandline::get('lastname'),
        'mail'         => SpotCommandline::get('mail'),
        'userid'       => -1,
    ];
    $svcValidate = new ServicesValidateUserRecord($daoFactory, $spotSettings);
    $errors = $svcValidate->validateUserRecord($spotUser, false)->getErrors();
    if (!empty($errors)) {
        fail($errors[0]);
    }

    $svcUserRecord = new Services_User_Record($daoFactory, $spotSettings);
    $spotUser['userid'] = $svcUserRecord->createUserRecord($spotUser)->getData('userid');
    $svcUserRecord->setUserPassword($spotUser);

    // The built-in 'admin' account gets the same password and a fresh API
    // key, so neither keeps its well-known default.
    $adminUser = $svcUserRecord->getUser(SPOTWEB_ADMIN_USERID);
    $adminUser['newpassword1'] = $password;
    $svcUserRecord->setUserPassword($adminUser);
    $svcUserRecord->resetUserApi($svcUserRecord->getUser(SPOTWEB_ADMIN_USERID));

    $spotSettings->set('custom_admin_userid', $spotUser['userid']);
    $spotSettings->set('systemtype', $systemType);
    $svcUpgradeBase->resetSystemType($systemType);

    echo 'Spotweb installed, administrator: '.$spotUser['username'].PHP_EOL;
} catch (Exception $x) {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    fail($x->getMessage());
}
