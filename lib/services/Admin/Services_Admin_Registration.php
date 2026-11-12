<?php

/*
 * Controls who may create an account.
 *
 * Spotweb decides this with the "create new user" permission. Visitors that
 * are not logged in act as the anonymous user, so registration is open when
 * one of the anonymous user's groups holds that permission. This service
 * reads and changes exactly that, so the admin page and the security group
 * editor always agree.
 */
class Services_Admin_Registration
{
    const MODE_ADMINS = 'admins';
    const MODE_OPEN = 'open';

    /** @var Services_Settings_Container */
    private $_settings;
    /** @var Dao_User */
    private $_userDao;

    public function __construct(Services_Settings_Container $settings, Dao_User $userDao)
    {
        $this->_settings = $settings;
        $this->_userDao = $userDao;
    }

    public static function validModes()
    {
        return [self::MODE_ADMINS, self::MODE_OPEN];
    }

    /*
     * Ids of the security groups the anonymous user is a member of
     */
    private function getAnonymousGroupIds()
    {
        $anonId = (int) $this->_settings->get('nonauthenticated_userid');
        $groupIds = [];
        foreach ($this->_userDao->getGroupList($anonId) as $group) {
            if ($group['ismember']) {
                $groupIds[] = (int) $group['id'];
            } // if
        } // foreach

        return $groupIds;
    }

    private function groupHasCreatePerm($groupId)
    {
        foreach ($this->_userDao->getGroupPerms($groupId) as $perm) {
            if (((int) $perm['permissionid'] === SpotSecurity::spotsec_create_new_user) && empty($perm['deny'])) {
                return true;
            } // if
        } // foreach

        return false;
    }

    public function getMode()
    {
        foreach ($this->getAnonymousGroupIds() as $groupId) {
            if ($this->groupHasCreatePerm($groupId)) {
                return self::MODE_OPEN;
            } // if
        } // foreach

        return self::MODE_ADMINS;
    }

    public function setMode($mode)
    {
        if (!in_array($mode, self::validModes(), true)) {
            throw new InvalidArgumentException('Invalid registration mode');
        } // if

        $perm = ['permissionid' => SpotSecurity::spotsec_create_new_user, 'objectid' => ''];
        foreach ($this->getAnonymousGroupIds() as $groupId) {
            $hasPerm = $this->groupHasCreatePerm($groupId);

            if (($mode === self::MODE_OPEN) && !$hasPerm) {
                // a denied entry may exist; replace it by a plain grant
                $this->_userDao->removePermFromSecGroup($groupId, $perm);
                $this->_userDao->addPermToSecGroup($groupId, $perm);
            } elseif (($mode === self::MODE_ADMINS) && $hasPerm) {
                $this->_userDao->removePermFromSecGroup($groupId, $perm);
            } // elseif
        } // foreach
    }

    /*
     * All security groups, flagged with whether new users are put in them
     */
    public function getNewUserGroups()
    {
        $current = [];
        foreach ((array) $this->_settings->get('newuser_grouplist') as $entry) {
            $current[(int) $entry['groupid']] = true;
        } // foreach

        $groups = [];
        foreach ($this->_userDao->getGroupList(null) as $group) {
            $groups[] = ['id' => (int) $group['id'],
                'name'         => $group['name'],
                'selected'     => isset($current[(int) $group['id']]), ];
        } // foreach

        return $groups;
    }

    /*
     * Stores the groups a new user is put in. Returns false when the
     * selection is not usable (empty, or unknown group ids).
     */
    public function setNewUserGroups(array $groupIds)
    {
        $known = [];
        foreach ($this->_userDao->getGroupList(null) as $group) {
            $known[(int) $group['id']] = true;
        } // foreach

        $groupIds = array_values(array_unique(array_map('intval', $groupIds)));
        sort($groupIds);
        if (empty($groupIds)) {
            return false;
        } // if

        $list = [];
        foreach ($groupIds as $prio => $groupId) {
            if (!isset($known[$groupId])) {
                return false;
            } // if
            $list[] = ['groupid' => $groupId, 'prio' => $prio + 1];
        } // foreach

        $this->_settings->set('newuser_grouplist', $list);

        return true;
    }
}
