<?php

/*
 * "Forgot password?" and the "choose your password" link in welcome mails.
 *
 * Links are signed, not stored: the signature covers the user id, the
 * expiry time and the current password hash. So a link works only until
 * it expires or until the password changes, which makes it single use
 * without any database table.
 */
class Services_User_PasswordReset
{
    const RESET_TTL = 3600;        // 1 hour
    const WELCOME_TTL = 172800;    // 48 hours
    const THROTTLE = 300;          // at most one reset mail per user per 5 minutes
    const MIN_PASSWORD = 8;

    /** @var Services_Settings_Container */
    private $_settings;
    /** @var Dao_Factory */
    private $_daoFactory;
    /** @var Dao_User */
    private $_userDao;

    public function __construct(Dao_Factory $daoFactory, Services_Settings_Container $settings)
    {
        $this->_daoFactory = $daoFactory;
        $this->_settings = $settings;
        $this->_userDao = $daoFactory->getUserDao();
    }

    public function isEnabled()
    {
        $enabled = $this->_settings->get('password_reset_enabled');

        return ($enabled === null) ? true : (bool) $enabled;
    }

    private function secret()
    {
        $secret = $this->_settings->get('mail_token_secret');
        if (!is_string($secret) || strlen($secret) < 32) {
            $secret = bin2hex(random_bytes(32));
            $this->_settings->set('mail_token_secret', $secret);
        } // if

        return $secret;
    }

    private function isResettable($userId)
    {
        $userId = (int) $userId;

        return ($userId > 0) && ($userId !== (int) $this->_settings->get('nonauthenticated_userid')) && ($userId !== SPOTWEB_ANONYMOUS_USERID);
    }

    public function createToken($userId, $ttl)
    {
        $passHash = $this->_userDao->getUserPassHash($userId);
        if ($passHash === false) {
            return false;
        } // if
        $expires = time() + (int) $ttl;

        return (int) $userId.'.'.$expires.'.'.$this->sign((int) $userId, $expires, $passHash);
    }

    private function sign($userId, $expires, $passHash)
    {
        $raw = hash_hmac('sha256', $userId.'|'.$expires.'|'.$passHash, $this->secret(), true);

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /*
     * The user record the token belongs to, or false when the token is
     * invalid, expired or already used.
     */
    public function verifyToken($token)
    {
        if (!is_string($token) || !preg_match('/^(\d{1,10})\.(\d{10})\.([A-Za-z0-9_-]{43})$/', $token, $m)) {
            return false;
        } // if
        $userId = (int) $m[1];
        $expires = (int) $m[2];
        if ($expires < time() || !$this->isResettable($userId)) {
            return false;
        } // if
        $passHash = $this->_userDao->getUserPassHash($userId);
        if ($passHash === false || !hash_equals($this->sign($userId, $expires, $passHash), $m[3])) {
            return false;
        } // if

        return $this->_userDao->getUser($userId);
    }

    public function makeLink($token)
    {
        $base = (string) $this->_settings->get('spotweburl');

        return rtrim($base, '/').'/?page=resetpassword&token='.rawurlencode($token);
    }

    /*
     * Handle "Forgot password?". Always returns true so the form does not
     * tell visitors which usernames or addresses exist; failures to send
     * are logged.
     */
    public function requestReset($identifier)
    {
        $identifier = trim((string) $identifier);
        if (($identifier === '') || !$this->isEnabled()) {
            return true;
        } // if

        $userId = (strpos($identifier, '@') !== false)
                    ? $this->_userDao->findUserIdForMail($identifier)
                    : $this->_userDao->findUserIdForName($identifier);
        if (empty($userId) || !$this->isResettable($userId)) {
            return true;
        } // if
        $userId = (int) $userId;

        // throttle per user so the form cannot be used to flood a mailbox
        $sent = $this->_settings->get('password_reset_sent');
        $sent = is_array($sent) ? $sent : [];
        $now = time();
        $sent = array_filter($sent, function ($stamp) use ($now) {
            return $stamp > $now - self::THROTTLE;
        });
        if (isset($sent[$userId])) {
            return true;
        } // if
        $sent[$userId] = $now;
        $this->_settings->set('password_reset_sent', $sent);

        $user = $this->_userDao->getUser($userId);
        if (empty($user) || !filter_var($user['mail'], FILTER_VALIDATE_EMAIL)) {
            return true;
        } // if

        try {
            $this->mailInUserLanguage($user, function ($user) {
                $link = $this->makeLink($this->createToken($user['userid'], self::RESET_TTL));
                $subject = _('Reset your Spotweb password');
                $body = sprintf(_('Hello %s,'), $this->displayName($user))."\n\n"
                      .sprintf(_('Someone (hopefully you) asked to reset the password of the account "%s" on %s.'), $user['username'], $this->siteName())."\n\n"
                      ._('Choose a new password with the link below. It works once, for 60 minutes.')."\n\n"
                      .$link."\n\n"
                      ._('Did not ask for this? Then ignore this e-mail; your password stays the same.');

                return [$subject, $body];
            });
        } catch (Exception $e) {
            error_log('Spotweb: password reset mail to user '.$userId.' failed: '.$e->getMessage());
        } // catch

        return true;
    }

    /*
     * Welcome mail for a new account, with a link to choose a password
     * instead of a password in plain text. Throws on failure.
     */
    public function sendWelcome(array $user)
    {
        $this->mailInUserLanguage($user, function ($user) {
            $link = $this->makeLink($this->createToken($user['userid'], self::WELCOME_TTL));
            $subject = sprintf(_('Your account on %s'), $this->siteName());
            $body = sprintf(_('Hello %s,'), $this->displayName($user))."\n\n"
                  .sprintf(_('An account has been created for you on %s.'), $this->siteName())."\n\n"
                  .sprintf(_('Your username: %s'), $user['username'])."\n\n"
                  ._('Choose your password with the link below. It works for 48 hours.')."\n\n"
                  .$link."\n\n"
                  ._('Link expired? Use "Forgot password?" on the login screen to get a new one.');

            return [$subject, $body];
        });
    }

    /*
     * Set the new password from the reset form
     */
    public function resetPassword($token, $password1, $password2)
    {
        $result = new Dto_FormResult();
        $user = $this->verifyToken($token);
        if ($user === false) {
            $result->addError(_('This link is invalid, expired or already used. Request a new one.'));

            return $result;
        } // if
        if (strlen((string) $password1) < self::MIN_PASSWORD) {
            $result->addError(sprintf(_('Use at least %d characters'), self::MIN_PASSWORD));
        } // if
        if ((string) $password1 !== (string) $password2) {
            $result->addError(_('Passwords do not match'));
        } // if
        if (!$result->isSuccess()) {
            return $result;
        } // if

        $svcUserRecord = new Services_User_Record($this->_daoFactory, $this->_settings);
        $svcUserRecord->setUserPassword(['userid' => $user['userid'], 'newpassword1' => $password1]);

        // sign out everywhere: whoever knew the old password is out
        $this->_daoFactory->getSessionDao()->deleteAllUserSessions($user['userid']);

        $result->addData('username', $user['username']);
        $result->setResult('success');

        return $result;
    }

    private function mailInUserLanguage(array $user, callable $compose)
    {
        $previous = SpotTranslation::current();
        $lang = $user['prefs']['user_language'] ?? $previous;
        if ($lang !== $previous) {
            SpotTranslation::initialize($lang);
        } // if
        try {
            list($subject, $body) = $compose($user);
            $html = Services_Mail_Sender::htmlLayout($subject, $body, (string) $this->_settings->get('spotweburl'));
        } finally {
            if ($lang !== $previous) {
                SpotTranslation::initialize($previous);
            } // if
        } // finally

        Services_Mail_Sender::fromSettings($this->_settings)->send($user['mail'], $subject, $body, $html);
    }

    private function displayName(array $user)
    {
        $name = trim(($user['firstname'] ?? '').' '.($user['lastname'] ?? ''));

        return ($name !== '') ? $name : $user['username'];
    }

    private function siteName()
    {
        $host = parse_url((string) $this->_settings->get('spotweburl'), PHP_URL_HOST);

        return ($host && $host !== 'mijnuniekeservernaam') ? $host : 'Spotweb';
    }
} // Services_User_PasswordReset
