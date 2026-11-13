<?php

class Services_Actions_CreateUser
{
    private $_settings;
    private $_daoFactory;
    private $_svcUserRecord;

    public function __construct(Services_Settings_Container $settings, Dao_Factory $daoFactory)
    {
        $this->_settings = $settings;
        $this->_daoFactory = $daoFactory;

        $this->_svcUserRecord = new Services_User_Record($this->_daoFactory, $this->_settings);
    }

    // ctor

    /*
     * Create a new user record
     */
    public function createNewUser(array $spotUser, array $spotSession)
    {
        $result = $this->_svcUserRecord->createUserRecord($spotUser);
        if ($result->isSuccess()) {
            $spotUser = $result->getData('userrecord');

            /**
             * We do not want the complete user record to be passed as JSON, so
             * we remove it again.
             */
            $result->removeData('userrecord');

            // Initialize notification system
            $spotsNotifications = new SpotNotifications($this->_daoFactory, $this->_settings, $spotSession);

            // Send a mail to the new user if the user asked for this
            $sendMail = isset($spotUser['sendmail']);
            $alwaysSend = (bool) $this->_settings->get('sendwelcomemail');
            if ($sendMail || $alwaysSend) {
                if ($spotsNotifications->sendNewUserMail($spotUser, $alwaysSend)) {
                    $result->addInfo(sprintf(_('A welcome e-mail was sent to %s'), htmlspecialchars($spotUser['mail'], ENT_QUOTES)));
                } else {
                    $result->addWarning(_('The welcome e-mail could not be sent. Check Admin > E-mail.'));
                } // else
            } // if

            // send a notification that a new user was added to the system
            $spotsNotifications->sendUserAdded($result->getData('username'), $result->getData('password'));
        } // if

        return $result;
    }

    // createNewUser
} // Services_Actions_Createuser
