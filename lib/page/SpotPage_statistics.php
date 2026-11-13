<?php

class SpotPage_statistics extends SpotPage_Abs
{
    private $_params;

    public function __construct(Dao_Factory $daoFactory, Services_Settings_Container $settings, array $currentSession, array $params)
    {
        parent::__construct($daoFactory, $settings, $currentSession);

        $this->_params = $params;
    }

    // ctor

    public function render()
    {
        // Validate permissions
        $this->_spotSec->fatalPermCheck(SpotSecurity::spotsec_view_statistics, '');

        // init
        $svcUserFilter = new Services_User_Filters($this->_daoFactory, $this->_settings);

        // set the page title
        $this->_pageTitle = _('Statistics');

        //- display stuff -#
        $svcSearchQp = new Services_Search_QueryParser($this->_daoFactory->getConnection());
        $parsedSearch = $svcSearchQp->filterToQuery(
            '',
            ['field' => '', 'direction' => ''],
            $this->_currentSession,
            $svcUserFilter->getIndexFilter($this->_currentSession['user']['userid'])
        );

        $svcPrvStats = new Services_Providers_Statistics(
            $this->_daoFactory->getSpotDao(),
            $this->_daoFactory->getCacheDao(),
            $this->_daoFactory->getUsenetStateDao()->getLastUpdate(Dao_UsenetState::State_Spots)
        );
        $dashboard = $svcPrvStats->getDashboard(isset($this->_params['limit']) ? (string) $this->_params['limit'] : '');

        $this->template('statistics', ['quicklinks' => $this->_settings->get('quicklinks'),
            'dashboard'                             => $dashboard,
            'filters'                               => $svcUserFilter->getFilterList($this->_currentSession['user']['userid'], 'filter'),
            'parsedsearch'                          => $parsedSearch,
            'limit'                                 => $this->_params['limit'], ]);
    }

    // render
} // class SpotPage_statistics
