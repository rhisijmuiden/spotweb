<?php

/*
 * Admin > E-mail: send a test mail with the settings that are in the
 * form right now (saved or not), and show the SMTP conversation.
 */
class SpotPage_adminmail extends SpotPage_Abs
{
    private $_form;

    public function __construct(Dao_Factory $daoFactory, Services_Settings_Container $settings, array $currentSession, array $params)
    {
        parent::__construct($daoFactory, $settings, $currentSession);
        $this->_form = $params['adminmailform'];
    }

    public function render()
    {
        $this->_spotSec->fatalPermCheck(SpotSecurity::spotsec_edit_settings, '');
        $this->sendExpireHeaders(true);
        header('Content-Type: application/json; charset=utf-8');

        // the action is only set when the XSRF token is valid
        if ($this->_form['action'] !== 'test') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => _('Your session expired. Reload the page and try again.'), 'log' => []]);

            return;
        } // if

        $current = $this->_settings->get('smtp');
        $smtpForm = (isset($this->_form['smtp']) && is_array($this->_form['smtp'])) ? $this->_form['smtp'] : [];
        list($smtp, $errors) = Services_Mail_Sender::fromForm($smtpForm, is_array($current) ? $current : []);
        $from = trim((string) ($this->_form['from'] ?? ''));
        $to = trim((string) ($this->_form['to'] ?? ''));

        if (!empty($errors)) {
            echo json_encode(['ok' => false, 'message' => implode(' ', $errors), 'log' => []]);

            return;
        } // if

        $sender = new Services_Mail_Sender($smtp, $from, $this->_settings->get('spotweburl'));
        $started = microtime(true);
        $method = $smtp['use'] ? sprintf('SMTP %s:%d (%s)', $smtp['host'], $smtp['port'], $smtp['encryption']) : _('PHP mail() function');
        $body = _('This is a test e-mail from Spotweb. When you read this, the e-mail settings work.')."\n\n"
              .sprintf(_('Sent via: %s'), $method)."\n"
              .sprintf(_('Sent by: %s'), $this->_currentSession['user']['username'])."\n"
              .sprintf(_('Time: %s'), date('Y-m-d H:i:s T'));

        try {
            $sender->send($to, _('Spotweb test e-mail'), $body, null, true);
            echo json_encode([
                'ok'      => true,
                'message' => sprintf(_('Test e-mail sent to %s in %.1f seconds. Check the inbox (and the spam folder).'), $to, microtime(true) - $started),
                'log'     => $sender->getLog(),
            ]);
        } catch (Services_Mail_Exception $e) {
            echo json_encode(['ok' => false, 'message' => $e->getMessage(), 'log' => $sender->getLog()]);
        } // catch
    }
} // class SpotPage_adminmail
