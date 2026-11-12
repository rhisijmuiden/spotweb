<?php

class SpotPage_editsettings extends SpotPage_Abs
{
    private $_editSettingsForm;

    public function __construct(Dao_Factory $daoFactory, Services_Settings_Container $settings, array $currentSession, array $params)
    {
        parent::__construct($daoFactory, $settings, $currentSession);
        $this->_editSettingsForm = $params['editsettingsform'];
    }

    // ctor

    public function render()
    {
        // Validate proper permissions
        $this->_spotSec->fatalPermCheck(SpotSecurity::spotsec_edit_settings, '');

        // Make sure the editresult is set to 'not comited' per default
        $result = new Dto_FormResult('notsubmitted');

        // set the page title
        $this->_pageTitle = _('Settings');

        /*
         * bring the forms' action into the local scope for
         * easier access
         */
        $formAction = $this->_editSettingsForm['action'];

        // Are we trying to submit this form, or only rendering it?
        if (!empty($formAction)) {
            switch ($formAction) {
                case 'edit':
                    // Validate and apply all settings
                    $svcSettings = new Services_Settings_Base($this->_settings, $this->_daoFactory->getBlackWhiteListDao());

                    // registration is stored as permissions, not as a setting
                    $registration = (isset($this->_editSettingsForm['registration']) && is_array($this->_editSettingsForm['registration']))
                                        ? $this->_editSettingsForm['registration'] : null;
                    unset($this->_editSettingsForm['registration']);

                    $result = $svcSettings->validateSettings($this->_editSettingsForm);

                    if ($registration !== null) {
                        $mode = $registration['mode'] ?? '';
                        $groups = (isset($registration['groups']) && is_array($registration['groups'])) ? $registration['groups'] : [];
                        if (!in_array($mode, Services_Admin_Registration::validModes(), true)) {
                            $result->addError(_('Invalid registration setting'));
                        } elseif (empty($groups)) {
                            $result->addError(_('Choose at least one group for new users'));
                        } // elseif
                    } // if

                    if ($result->isSuccess()) {
                        // and actually update the user in the database
                        $newSettings = $result->getData('settings');
                        $svcSettings->setSettings($newSettings);

                        if ($registration !== null) {
                            $svcRegistration = new Services_Admin_Registration($this->_settings, $this->_daoFactory->getUserDao());
                            $svcRegistration->setMode($mode);
                            if (!$svcRegistration->setNewUserGroups($groups)) {
                                $result->addError(_('Choose at least one group for new users'));
                            } // if
                        } // if
                    } // if

                    break;
                    // case 'edit'

                case 'cancel':
                    $result = new Dto_FormResult('success');
                 // case 'cancel'
            } // switch
        } // if

        //- display stuff -#
        $svcRegistration = new Services_Admin_Registration($this->_settings, $this->_daoFactory->getUserDao());

        // activity figures for Admin > Activity
        $svcStats = new Services_Stats_Requests($this->_daoFactory->getConnection());
        try {
            $activity = ['daily' => $svcStats->getDaily(14),
                'topusers'       => $svcStats->getTopUsers(30, 10),
                'downloads'      => $svcStats->getDownloadSummary(7),
                'spots'          => $svcStats->getSpotRange(), ];
        } catch (Exception $x) {
            // tables missing until bin/upgrade-db.php has run
            $activity = null;
        } // catch

        $this->template('editsettings', ['editsettingsform' => $this->_settings,
            'activity'                                      => $activity,
            'registrationmode'                              => $svcRegistration->getMode(),
            'newusergroups'                                 => $svcRegistration->getNewUserGroups(),
            'result'                                        => $result,
            'http_referer'                                  => $this->_editSettingsForm['http_referer'], ]);
    }

    // render
} // class SpotPage_editsettings
