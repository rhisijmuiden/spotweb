<?php

/*
 * "Forgot password?" (request a link) and "Choose a new password"
 * (follow the link from the e-mail). Works for visitors that are not
 * logged in, in every template.
 */
class SpotPage_resetpassword extends SpotPage_Abs
{
    private $_form;
    private $_token;

    public function __construct(Dao_Factory $daoFactory, Services_Settings_Container $settings, array $currentSession, array $params)
    {
        parent::__construct($daoFactory, $settings, $currentSession);
        $this->_form = $params['resetpasswordform'];
        $this->_token = is_string($params['token']) ? $params['token'] : '';
    }

    public function render()
    {
        $this->_spotSec->fatalPermCheck(SpotSecurity::spotsec_perform_login, '');

        $svcReset = new Services_User_PasswordReset($this->_daoFactory, $this->_settings);
        $view = ['mode' => 'request', 'errors' => [], 'token' => '', 'username' => '', 'identifier' => ''];

        if (!$svcReset->isEnabled()) {
            $view['mode'] = 'disabled';
        } elseif ($this->_token !== '') {
            $view['token'] = $this->_token;
            $user = $svcReset->verifyToken($this->_token);
            if ($user === false) {
                $view['mode'] = 'invalid';
            } else {
                $view['mode'] = 'choose';
                $view['username'] = $user['username'];
                if ($this->_form['action'] === 'reset') {
                    $result = $svcReset->resetPassword($this->_token, $this->_form['password1'] ?? '', $this->_form['password2'] ?? '');
                    if ($result->isSuccess()) {
                        $view['mode'] = 'done';
                    } else {
                        $view['errors'] = $result->getErrors();
                    } // else
                } // if
            } // else
        } elseif ($this->_form['action'] === 'request') {
            $view['identifier'] = (string) ($this->_form['identifier'] ?? '');
            $svcReset->requestReset($view['identifier']);
            $view['mode'] = 'sent';
        } // elseif

        $this->sendExpireHeaders(true);
        $this->sendContentTypeHeader('html');
        header('Referrer-Policy: no-referrer');
        header('X-Frame-Options: DENY');

        $settings = $this->_settings;
        $tplHelper = $this->_tplHelper;
        require __DIR__.'/../../templates/modern/resetpassword.inc.php';
    }
} // class SpotPage_resetpassword
