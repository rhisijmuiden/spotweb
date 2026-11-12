<?php

/*
 * Admin > Retrieve: start a retrieve from the browser and follow its
 * progress. Works without exec() or a shell: the retrieve runs in this
 * PHP request after the browser already got its answer.
 */
class SpotPage_adminretrieve extends SpotPage_Abs
{
    private $_form;
    private $_action;

    public function __construct(Dao_Factory $daoFactory, Services_Settings_Container $settings, array $currentSession, array $params)
    {
        parent::__construct($daoFactory, $settings, $currentSession);
        $this->_form = $params['adminretrieveform'];
        $this->_action = $params['action'];
    }

    public function render()
    {
        $this->_spotSec->fatalPermCheck(SpotSecurity::spotsec_edit_settings, '');
        $this->_spotSec->fatalPermCheck(SpotSecurity::spotsec_retrieve_spots, '');

        $this->sendExpireHeaders(true);
        $conn = $this->_daoFactory->getConnection();

        // the form action is only set when the XSRF token is valid
        if (($this->_form['action'] === 'start') || ($this->_form['action'] === 'backfill')) {
            $this->start($conn, $this->_form['action'] === 'backfill');

            return;
        } // if

        $this->sendJson(Services_Retriever_Progress::read($conn));
    }

    private function start(dbeng_abs $conn, $backfill)
    {
        $state = Services_Retriever_Progress::read($conn);
        if ($state['running'] || $this->_daoFactory->getUsenetStateDao()->isRetrieverRunning()) {
            http_response_code(409);
            $this->sendJson(['started' => false, 'message' => _('A retrieve is already running.')] + $state);

            return;
        } // if

        // answer the browser first, then keep running
        $answer = json_encode(['started' => true]);
        ignore_user_abort(true);
        @set_time_limit(0);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Length: '.strlen($answer));
        header('Connection: close');
        echo $answer;
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            while (ob_get_level() > 0) {
                ob_end_flush();
            } // while
            flush();
        } // else

        define('SPOTWEB_RETRIEVE_FROM_ADMIN', true);
        if ($backfill) {
            define('SPOTWEB_RETRIEVE_BACKFILL', true);
        } // if
        $GLOBALS['spotwebRetrieveSession'] = $this->_currentSession;
        $GLOBALS['spotwebRetrieveBoot'] = [$this->_settings, $this->_daoFactory, null];

        // retrieve.php prints its progress for the console; we do not need that here
        ob_start(function () { return ''; });
        try {
            require __DIR__.'/../../retrieve.php';
        } finally {
            ob_end_clean();
        } // finally
    }

    private function sendJson($data)
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
    }
} // class SpotPage_adminretrieve
