<?php

error_reporting(2147483647);

try {
    @ini_set('memory_limit', '512M');
    require_once __DIR__.'/vendor/autoload.php';
    require_once __DIR__.'/lib/services/Retriever/Services_Retriever_CommandLock.php';
    set_time_limit(0);

    /*
     * Initialize the Spotweb base classes
     */
    if (defined('SPOTWEB_RETRIEVE_FROM_ADMIN')) {
        // started from Admin > Retrieve, which already booted Spotweb
        list($settings, $daoFactory, $req) = $GLOBALS['spotwebRetrieveBoot'];
    } else {
        $bootstrap = new Bootstrap();
        list($settings, $daoFactory, $req) = $bootstrap->boot();
    } // else

    /*
     * disable timing, all queries which are ran by retrieve this would make it use
     * large amounts of memory
     */
    SpotTiming::disable();

    // Initialize commandline arguments
    SpotCommandline::initialize(['force', 'debug', 'retro', 'timing', 'backfill'], ['force' => false, 'timing' => false, 'debug' => false, 'retro' => false, 'backfill' => false]);

    // Allow for timing to be displayed after retrieval of spots
    $showTiming = SpotCommandline::get('timing');
    if ($showTiming) {
        SpotTiming::enable();
        SpotTiming::enableHtml(false);
        SpotTiming::disableExtra(true);
    } // if

    // Initialize translation to english
    SpotTranslation::initialize('en_US');

    /*
     * When retrieval is run from the webinterface, we want to make
     * sure this user is actually allowed to run retrieval.
     */
    $svcUserRecord = new Services_User_Record($daoFactory, $settings);
    $svcUserAuth = new Services_User_Authentication($daoFactory, $settings);
    if (defined('SPOTWEB_RETRIEVE_FROM_ADMIN')) {
        /*
         * Started from Admin > Retrieve: the admin page already checked the
         * permissions and hands us the admin's session
         */
        $userSession = $GLOBALS['spotwebRetrieveSession'];
        $userSession['session'] = ['ipaddr' => ''];
    } elseif (!SpotCommandline::isCommandline()) {
        ignore_user_abort(true);
        /*
         * An API key is required, so request it and try to
         * create a session with it which we can use to validate
         * the user with
         */
        $apiKey = $req->getDef('apikey', '');
        $userSession = $svcUserAuth->verifyApi($apiKey);

        /*
         * If the session failed or the the user doesn't have access
         * to retrieve spots, let the user know
         */
        if (($userSession === false) || (!$userSession['security']->allowed(SpotSecurity::spotsec_retrieve_spots, ''))) {
            throw new PermissionDeniedException(SpotSecurity::spotsec_retrieve_spots, '');
        } // if

        // Add the user's ip addres, we need it for sending notifications
        $userSession['session'] = ['ipaddr' => ''];
    } else {
        $userSession['user'] = $svcUserRecord->getUser(SPOTWEB_ADMIN_USERID);
        $userSession['security'] = new SpotSecurity(
            $daoFactory->getUserDao(),
            $daoFactory->getAuditDao(),
            $settings,
            $userSession['user'],
            ''
        );
        $userSession['session'] = ['ipaddr' => ''];
    } // if

    /*
     * We normally check whether we are not running already, because
     * this would mean it will mess up all sorts of things like
     * comment calculation, but a user can force our hand
     */
    $forceMode = SpotCommandline::get('force');

    /*
     * Do we need to debuglog this session? Generates loads of
     * output
     */
    $debugLog = SpotCommandline::get('debug');
    if ($debugLog) {
        SpotDebug::enable(SpotDebug::TRACE);
    } else {
        SpotDebug::disable();
    } // if

    /*
     * Retro mode will allow os to start from the beginning and retrieve
     * all spots starting from scratch
    */
    $retroMode = SpotCommandline::get('retro');

    /*
     * Backfill: fetch spots older than the oldest one we have, back to the
     * "retrieve spots newer than" date (php retrieve.php --backfill, or
     * Admin > Retrieve)
     */
    $backfillMode = (bool) SpotCommandline::get('backfill') || defined('SPOTWEB_RETRIEVE_BACKFILL');

    $retrieveLock = new Services_Retriever_CommandLock($daoFactory->getConnection(), Services_Retriever_CommandLock::LockName, __DIR__);
    if (!$retrieveLock->acquire()) {
        throw new RetrieverRunningException();
    } // if

    try {
        $daoFactory->getUsenetStateDao()->initialize();
        $daoFactory->getUsenetStateDao()->setRetrieverRunning(true);

        // share progress with Admin > Retrieve
        Services_Retriever_Progress::attach($daoFactory->getConnection());
        Services_Retriever_Progress::start(defined('SPOTWEB_RETRIEVE_FROM_ADMIN') ? 'admin' : 'cron', ($retroMode || $backfillMode) ? 'backfill' : 'normal');

        /*
         * Retention cleanup. Basically when we ask for Spotweb to only
         * keep spots for 'xx' days (eg: 30 days), we either have to delete
         * everyting older than 'xx' days, or delete all 'full' resources
         * older than the specified time period.
         *
         * The full resources are everything beyond the bare minimum to
         * display the spots, so we delete nzb's, images, comments, etc.
         */
        if (($settings->get('retention') > 0) && (!$retroMode) && (!$backfillMode)) {
            echo 'Removing Spot information which is beyond retention period,';

            $spotDao = $daoFactory->getSpotDao();
            $cacheDao = $daoFactory->getCacheDao();
            $commentDao = $daoFactory->getCommentDao();

            switch ($settings->get('retentiontype')) {
                case 'everything':
                    $spotDao->deleteSpotsRetention($settings->get('retention'));
                    $cacheDao->expireCache($settings->get('retention'));
                    // case everything

                case 'fullonly':
                    $cacheDao->expireCache($settings->get('retention'));
                    $commentDao->expireCommentsFull($settings->get('retention'));
                    $spotDao->expireSpotsFull($settings->get('retention'));
                 // case fullonly
            } // switch

            echo ', done'.PHP_EOL;
        } // if

        $newSpotCount = 0;
        $newCommentCount = 0;
        $newReportCount = 0;
        $retriever = null;

        //# Spots
        /*
         * Actually retrieve spots from the server
         */
        if ($backfillMode) {
            $oldestSpot = (int) $daoFactory->getConnection()->singleQuery('SELECT MIN(stamp) FROM spots');
            $backfillFrom = max(1230789600, (int) $settings->get('retrieve_newer_than'));
            if (($oldestSpot === 0) || ($backfillFrom >= $oldestSpot)) {
                echo 'Nothing to backfill: the oldest spot is already older than the start date.'.PHP_EOL;
                $retriever = null;
                $newSpotCount = 0;
            } else {
                echo 'Backfilling spots from '.date('Y-m-d', $backfillFrom).' to '.date('Y-m-d', $oldestSpot).PHP_EOL;
                $retriever = new Services_Retriever_Backfill($daoFactory, $settings, $backfillFrom, $oldestSpot);
                $newSpotCount = $retriever->perform();
            } // else
        } else {
            $retriever = new Services_Retriever_Spots(
                $daoFactory,
                $settings,
                $forceMode,
                $retroMode
            );
            $newSpotCount = $retriever->perform();
        } // else
        Services_Retriever_Progress::addTotal('spots', $newSpotCount);

        // Show the cumulative timings of the spotsretrieval
        if ($showTiming) {
            SpotTiming::displayCumul();
            SpotTiming::clear();
        } // if

        //# Creating filter counts
        if ($newSpotCount > 0) {
            $svcPrv_cacheSpotCount = new Services_Actions_CacheNewSpotCount(
                $daoFactory->getUserFilterCountDao(),
                $daoFactory->getUserFilterDao(),
                $daoFactory->getSpotDao(),
                new Services_Search_QueryParser($daoFactory->getConnection())
            );
            echo 'Calculating how many spots are new';
            $notifyNewArray = $svcPrv_cacheSpotCount->cacheNewSpotCount();
            echo ', done.'.PHP_EOL;

            // Show the cumulative timings of the caching of these spots
            if ($showTiming) {
                SpotTiming::displayCumul();
                SpotTiming::clear();
            } // if
        } // if

        /*
         * Should we retrieve comments?
         */
        if ($settings->get('retrieve_comments') && !$backfillMode) {
            $retriever = new Services_Retriever_Comments(
                $daoFactory,
                $settings,
                $forceMode,
                $retroMode
            );
            Services_Retriever_Progress::phase('comments');
            $newCommentCount = $retriever->perform();
            Services_Retriever_Progress::addTotal('comments', $newCommentCount);

            // Show the cumulative timings of the caching of these comments
            if ($showTiming) {
                SpotTiming::displayCumul();
                SpotTiming::clear();
            } // if
        } // if

        /*
         * Retrieval of reports
         */
        if ($settings->get('retrieve_reports') && !$retroMode && !$backfillMode) {
            $retriever = new Services_Retriever_Reports(
                $daoFactory,
                $settings,
                $forceMode,
                $retroMode
            );
            Services_Retriever_Progress::phase('reports');
            $newReportCount = $retriever->perform();
            Services_Retriever_Progress::addTotal('reports', $newReportCount);

            // Show the cumulative timings of the caching of these reports
            if ($showTiming) {
                SpotTiming::displayCumul();
                SpotTiming::clear();
            } // if
        } // if

        /*
         * SpotStateList cleanup
         */
        $daoFactory->getSpotStateListDao()->cleanSpotStateList();

        try {
            if ($settings->get('external_blacklist') || $settings->get('external_whitelist')) {
                $svcBwListRetriever = new Services_BWList_Retriever($daoFactory->getBlackWhiteListDao(), $daoFactory->getCacheDao());
            }

            //# External blacklist
            if ($settings->get('external_blacklist')) {
                $bwResult = $svcBwListRetriever->retrieveBlackList($settings->get('blacklist_url'));
                if ($bwResult === false) {
                    echo 'Blacklist not modified, no need to update'.PHP_EOL;
                } else {
                    echo 'Finished updating blacklist. Added '.$bwResult['added'].', removed '.$bwResult['removed'].', skipped '.$bwResult['skipped'].' of '.$bwResult['total'].' lines.'.PHP_EOL;
                } // else
            } // if

            //# External whitelist
            if ($settings->get('external_whitelist')) {
                $bwResult = $svcBwListRetriever->retrieveWhiteList($settings->get('whitelist_url'));
                if ($bwResult === false) {
                    echo 'Whitelist not modified, no need to update'.PHP_EOL;
                } else {
                    echo 'Finished updating whitelist. Added '.$bwResult['added'].', removed '.$bwResult['removed'].', skipped '.$bwResult['skipped'].' of '.$bwResult['total'].' lines.'.PHP_EOL;
                } // else
            } // if
        } catch (CorruptBWListException $e) {
            echo PHP_EOL.'Non-fatal: Updating black/whitelist failed, most likely unreachable!';
        }

        //# Statistics
        if ($settings->get('prepare_statistics') && $newSpotCount > 0) {
            if (extension_loaded('gd') || extension_loaded('gd2')) {
                $settings_nntp_hdr = $settings->get('nntp_hdr');
                $svcPrv_Stats = new Services_Providers_Statistics(
                    $daoFactory->getSpotDao(),
                    $daoFactory->getCachedao(),
                    $daoFactory->getUsenetStateDao()->getLastUpdate(Dao_UsenetState::State_Spots)
                );

                echo 'Starting to create statistics '.PHP_EOL;
                $svcPrv_Stats->createAllStatistics();
                echo 'Finished creating statistics '.PHP_EOL;
                echo PHP_EOL;
            } else {
                echo 'GD extension not loaded, not creating statistics'.PHP_EOL;
            } // else
        } // if

        // Verstuur notificaties
        $spotsNotifications = new SpotNotifications($daoFactory, $settings, $userSession);
        if (!empty($notifyNewArray)) {
            foreach ($notifyNewArray as $userId => $newSpotInfo) {
                foreach ($newSpotInfo as $filterInfo) {
                    if (($filterInfo['newcount'] > 0) && $filterInfo['enablenotify']) {
                        $spotsNotifications->sendNewSpotsForFilter($userId, $filterInfo['title'], $filterInfo['newcount']);
                    } // if
                } // foreach
            } // foreach
        } // if
        $spotsNotifications->sendRetrieverFinished($newSpotCount, $newCommentCount, $newReportCount);
        Services_Retriever_Progress::finish();
    } catch (Throwable $x) {
        Services_Retriever_Progress::fail($x->getMessage());

        throw $x;
    } finally {
        if ($retrieveLock->isAcquired()) {
            try {
                $daoFactory->getUsenetStateDao()->setRetrieverRunning(false);
            } catch (Exception $x) {
                SpotDebug::msg(SpotDebug::DEBUG, 'Unable to clear retriever running status: '.$x->getMessage());
            } // catch

            $retrieveLock->release();
        } // if
    } // finally
} catch (RetrieverRunningException $x) {
    echo PHP_EOL.PHP_EOL;
    echo 'retrieve.php is already running.'.PHP_EOL;
} catch (NntpException $x) {
    echo 'SpotWeb v'.SPOTWEB_VERSION.' on PHP v'.PHP_VERSION.' crashed'.PHP_EOL.PHP_EOL;
    echo 'Fatal error occured while connecting to the newsserver:'.PHP_EOL;
    echo '  ('.$x->getCode().') '.$x->getMessage().PHP_EOL;
    echo PHP_EOL.PHP_EOL;
    echo $x->getTraceAsString();
    echo PHP_EOL.PHP_EOL;
    if (isset($retriever) && is_object($retriever)) {
        $retriever->quit();
    }
} catch (DatabaseConnectionException $x) {
    echo 'Unable to connect to database: '.$x->getMessage().PHP_EOL;
} // catch

catch (InvalidOwnSettingsSettingException $x) {
    echo 'There is an error in your ownsettings.php'.PHP_EOL.PHP_EOL;
    echo $x->getMessage().PHP_EOL;
} // InvalidOwnSettingsSetting

catch (Exception $x) {
    echo PHP_EOL.PHP_EOL;
    echo 'SpotWeb v'.SPOTWEB_VERSION.' on PHP v'.PHP_VERSION.' crashed'.PHP_EOL.PHP_EOL;
    echo 'Fatal error '.get_class($x).' occured during retrieve:'.PHP_EOL;
    echo '  '.$x->getMessage().PHP_EOL.PHP_EOL;
    echo PHP_EOL.PHP_EOL;
    echo $x->getTraceAsString();
    echo PHP_EOL.PHP_EOL;
    if (isset($retriever) && is_object($retriever)) {
        $retriever->quit();
    }
    exit;
} // catch
