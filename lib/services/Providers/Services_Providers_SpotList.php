<?php

class Services_Providers_SpotList
{
    private $_spotDao;

    /*
     * constructor
     */
    public function __construct(Dao_Spot $spotDao)
    {
        $this->_spotDao = $spotDao;
    }

    // ctor

    /*
     * Returns a list of spots
     */
    public function fetchSpotList($ourUserId, $start, $limit, $parsedSearch)
    {
        SpotTiming::start(__CLASS__.'::'.__FUNCTION__);

        /*
         * Actually fetch the spots from the database
         */
        $spotResults = $this->_spotDao->getSpots($ourUserId, $start, $limit, $parsedSearch);

        SpotTiming::stop(__CLASS__.'::'.__FUNCTION__, []);

        return $spotResults;
    }

    // fetchSpotList()

    /*
     * Time windows (in seconds) tried in this order by fetchNewestSpotList(),
     * the final step searches without any window
     */
    const NEWEST_WINDOWS = [86400, 7 * 86400, 60 * 86400];

    /**
     * Returns the same list as fetchSpotList(), but is much faster for the
     * newest spots in a category with few spots, such as the feeds which
     * Prowlarr, Sonarr and Radarr poll.
     *
     * The database finds the newest spots by walking the date index from the
     * newest spot backwards and checking every spot against the filter. When
     * a category only has a handful of recent spots, that walk goes through
     * a large part of the database. We therefore first look in the last day,
     * then the last week and so on: a small date range is cheap to search,
     * and the newest spots of the whole database are the newest spots in that
     * range when the range holds enough of them.
     *
     * Only used when the list is sorted on date, newest first, and without a
     * text search (those use the fulltext index, which is fast already).
     */
    public function fetchNewestSpotList($ourUserId, $start, $limit, $parsedSearch, $now = null)
    {
        if (!self::canUseWindows($parsedSearch)) {
            return $this->fetchSpotList($ourUserId, $start, $limit, $parsedSearch);
        } // if

        SpotTiming::start(__CLASS__.'::'.__FUNCTION__);
        $now = ($now === null) ? time() : (int) $now;

        foreach (self::NEWEST_WINDOWS as $window) {
            /*
             * reversestamp is the negative stamp. Filtering on it (and not on
             * stamp) lets the database read just this range of the index the
             * list is sorted on, and stop as soon as it has enough spots.
             */
            $windowSearch = $parsedSearch;
            $windowFilter = '(s.reversestamp <= '.(int) (($now - $window) * -1).')';
            $windowSearch['filter'] = empty($parsedSearch['filter'])
                ? $windowFilter
                : '('.$parsedSearch['filter'].') AND '.$windowFilter;

            $spotResults = $this->_spotDao->getSpots($ourUserId, $start, $limit, $windowSearch);

            /*
             * When there are more spots than asked for within this window, we
             * have the newest spots of the whole database.
             */
            if ($spotResults['hasmore']) {
                SpotTiming::stop(__CLASS__.'::'.__FUNCTION__, [$window]);

                return $spotResults;
            } // if
        } // foreach

        /*
         * Not enough spots in the last weeks, so this search matches few
         * spots. For the first page, tell the database to read the matching
         * categories and sort them, instead of walking the date index through
         * every spot. Deeper pages keep the normal plan.
         */
        if ((int) ($start * $limit) === 0) {
            $parsedSearch['avoidDateIndexWalk'] = true;
        } // if

        $spotResults = $this->_spotDao->getSpots($ourUserId, $start, $limit, $parsedSearch);
        SpotTiming::stop(__CLASS__.'::'.__FUNCTION__, [0]);

        return $spotResults;
    }

    // fetchNewestSpotList

    /**
     * Whether fetchNewestSpotList() can search in time windows: the list is
     * sorted on date, newest first, and has no text search.
     */
    public static function canUseWindows($parsedSearch)
    {
        if (!empty($parsedSearch['additionalTables']) || !empty($parsedSearch['additionalJoins'])) {
            return false;
        } // if

        $sortFields = array_values(array_filter((array) $parsedSearch['sortFields']));
        if (empty($sortFields)) {
            return false;
        } // if
        $field = strtolower((string) $sortFields[0]['field']);
        if (!in_array($field, ['s.stamp', 'stamp'], true) || (strtoupper((string) $sortFields[0]['direction']) != 'DESC')) {
            return false;
        } // if

        $filter = (string) $parsedSearch['filter'];
        if (preg_match('/MATCH\s*\(|@@|s\.(title|poster|tag)\s+I?LIKE/i', $filter)) {
            return false;
        } // if

        return true;
    }

    // canUseWindows
} // Services_Providers_SpotList
