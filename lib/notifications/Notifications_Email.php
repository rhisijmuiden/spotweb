<?php

/*
 * E-mail notifications. The actual sending (SMTP, TLS, DKIM, HTML layout)
 * lives in Services_Mail_Sender, configured under Admin > E-mail.
 */
class Notifications_Email extends Notifications_abs
{
    private $_dataArray;
    private $_appName;

    public function __construct($appName, array $dataArray)
    {
        $this->_appName = $appName;
        $this->_dataArray = $dataArray;
    }

    // ctor

    public function register()
    {
    }

    // register

    public function sendMessage($type, $title, $body, $sourceUrl, $smtp)
    {
        $sender = new Services_Mail_Sender(is_array($smtp) ? $smtp : [], $this->_dataArray['sender'] ?? '', (string) $sourceUrl);

        try {
            $sender->send($this->_dataArray['receiver'] ?? '', $title, (string) $body);

            return true;
        } catch (Services_Mail_Exception $e) {
            // never break the page or the retrieve because a mail could not be sent
            error_log('Spotweb: e-mail notification failed: '.$e->getMessage());

            return false;
        } // catch
    }

    // sendMessage
} // Notifications_Email
