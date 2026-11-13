<?php

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

/*
 * Sends all e-mail for Spotweb: welcome mails, password resets,
 * notifications and the admin test mail.
 *
 * Admin > E-mail configures it. With SMTP turned off PHP's own mail()
 * is used. With SMTP on it supports STARTTLS and implicit TLS (SMTPS),
 * authentication (PLAIN, LOGIN, CRAM-MD5, XOAUTH2 via PHPMailer),
 * certificate checks with TLS 1.2 or newer, and optional DKIM signing.
 * Every mail goes out as UTF-8 with a plain text and an HTML part.
 */
class Services_Mail_Sender
{
    const ENC_STARTTLS = 'starttls';
    const ENC_SMTPS = 'smtps';
    const ENC_NONE = 'none';

    /** @var array */
    private $_config;
    /** @var string */
    private $_fromAddress;
    /** @var string */
    private $_siteUrl;
    /** @var string[] */
    private $_log = [];

    public function __construct(array $smtp, $fromAddress, $siteUrl = '')
    {
        $this->_config = self::normalize($smtp);
        $this->_fromAddress = trim((string) $fromAddress);
        $this->_siteUrl = (string) $siteUrl;
    }

    public static function fromSettings(Services_Settings_Container $settings)
    {
        $smtp = $settings->get('smtp');

        return new self(is_array($smtp) ? $smtp : [], $settings->get('systemfrommail'), $settings->get('spotweburl'));
    }

    public static function validEncryptions()
    {
        return [self::ENC_STARTTLS, self::ENC_SMTPS, self::ENC_NONE];
    }

    /*
     * Fill in missing keys. Settings saved by older versions only have
     * use, host, user, pass and port.
     */
    public static function defaults()
    {
        return [
            'use'            => false,
            'host'           => '',
            'port'           => 587,
            'encryption'     => self::ENC_STARTTLS,
            'auth'           => true,
            'user'           => '',
            'pass'           => '',
            'verify_cert'    => true,
            'timeout'        => 15,
            'from_name'      => 'Spotweb',
            'dkim_domain'    => '',
            'dkim_selector'  => '',
            'dkim_key'       => '',
            'dkim_pass'      => '',
        ];
    }

    public static function normalize(array $smtp)
    {
        $smtp = array_merge(self::defaults(), $smtp);
        $smtp['use'] = (bool) $smtp['use'];
        $smtp['auth'] = (bool) $smtp['auth'];
        $smtp['verify_cert'] = (bool) $smtp['verify_cert'];
        $smtp['port'] = (int) $smtp['port'];
        $smtp['timeout'] = max(5, min(120, (int) $smtp['timeout']));
        if (!in_array($smtp['encryption'], self::validEncryptions(), true)) {
            $smtp['encryption'] = self::ENC_STARTTLS;
        } // if

        return $smtp;
    }

    /*
     * Turn the posted admin form into a clean smtp setting. Returns
     * [settings, errors]. An empty password keeps the stored one, so
     * the password never has to be sent back to the browser.
     */
    public static function fromForm(array $form, array $current)
    {
        $current = self::normalize($current);
        $errors = [];
        $text = function ($key, $max = 255) use ($form) {
            return isset($form[$key]) && is_string($form[$key]) ? substr(trim($form[$key]), 0, $max) : '';
        };

        $smtp = [
            'use'           => !empty($form['use']),
            'host'          => $text('host'),
            'port'          => $text('port', 5),
            'encryption'    => $text('encryption', 10),
            'auth'          => !empty($form['auth']),
            'user'          => $text('user'),
            'pass'          => isset($form['pass']) && is_string($form['pass']) ? $form['pass'] : '',
            'verify_cert'   => !empty($form['verify_cert']),
            'timeout'       => $text('timeout', 3),
            'from_name'     => $text('from_name', 100),
            'dkim_domain'   => strtolower($text('dkim_domain')),
            'dkim_selector' => $text('dkim_selector', 63),
            'dkim_key'      => isset($form['dkim_key']) && is_string($form['dkim_key']) ? trim($form['dkim_key']) : '',
            'dkim_pass'     => isset($form['dkim_pass']) && is_string($form['dkim_pass']) ? $form['dkim_pass'] : '',
        ];

        // keep stored secrets when the field was left empty
        if ($smtp['pass'] === '' && empty($form['clear_pass'])) {
            $smtp['pass'] = $current['pass'];
        } // if
        if (!empty($form['clear_dkim'])) {
            $smtp['dkim_domain'] = $smtp['dkim_selector'] = $smtp['dkim_key'] = $smtp['dkim_pass'] = '';
        } elseif ($smtp['dkim_key'] === '') {
            $smtp['dkim_key'] = $current['dkim_key'];
            if ($smtp['dkim_pass'] === '') {
                $smtp['dkim_pass'] = $current['dkim_pass'];
            } // if
        } // if

        if ($smtp['encryption'] === '') {
            $smtp['encryption'] = self::ENC_STARTTLS;
        } // if
        if (!in_array($smtp['encryption'], self::validEncryptions(), true)) {
            $errors[] = _('Choose a valid SMTP encryption');
            $smtp['encryption'] = self::ENC_STARTTLS;
        } // if
        if ($smtp['port'] === '') {
            $smtp['port'] = ($smtp['encryption'] === self::ENC_SMTPS) ? 465 : 587;
        } // if
        if (!ctype_digit((string) $smtp['port']) || ((int) $smtp['port'] < 1) || ((int) $smtp['port'] > 65535)) {
            $errors[] = _('SMTP Port must be integer between 1 and 65535');
        } // if
        if ($smtp['timeout'] === '') {
            $smtp['timeout'] = 15;
        } // if
        if (!ctype_digit((string) $smtp['timeout'])) {
            $errors[] = _('The SMTP timeout must be a number of seconds');
        } // if
        if ($smtp['from_name'] === '') {
            $smtp['from_name'] = 'Spotweb';
        } // if
        if (preg_match('/[\r\n<>"]/', $smtp['from_name'])) {
            $errors[] = _('The sender name may not contain line breaks, quotes or "<" and ">"');
        } // if

        if ($smtp['use']) {
            if (($smtp['host'] === '') || !preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $smtp['host'])) {
                $errors[] = _('SMTP Hostname cannot be empty if SMTP is to be used');
            } // if
            if ($smtp['auth'] && (($smtp['user'] === '') || ($smtp['pass'] === ''))) {
                $errors[] = _('Enter the SMTP username and password, or turn off authentication');
            } // if
        } // if

        $hasDkim = ($smtp['dkim_domain'] !== '') || ($smtp['dkim_selector'] !== '') || ($smtp['dkim_key'] !== '');
        if ($hasDkim) {
            if (!preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)+$/', $smtp['dkim_domain'])) {
                $errors[] = _('Enter the DKIM domain, for example example.com');
            } // if
            if (!preg_match('/^[A-Za-z0-9_\-.]+$/', $smtp['dkim_selector'])) {
                $errors[] = _('Enter the DKIM selector, for example spotweb');
            } // if
            if (($smtp['dkim_key'] === '') || (openssl_pkey_get_private($smtp['dkim_key'], $smtp['dkim_pass']) === false)) {
                $errors[] = _('The DKIM private key is not a valid PEM key (or the passphrase is wrong)');
            } // if
        } // if

        return [self::normalize($smtp), $errors];
    }

    public function isSmtp()
    {
        return $this->_config['use'];
    }

    /*
     * The SMTP conversation of the last send, with credentials hidden.
     * Only filled when $withLog was true.
     */
    public function getLog()
    {
        return $this->_log;
    }

    /*
     * Send one mail. $textBody is plain text; an HTML version is built from
     * it unless $htmlBody is given. Throws Services_Mail_Exception on failure.
     */
    public function send($to, $subject, $textBody, $htmlBody = null, $withLog = false)
    {
        $this->_log = [];
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new Services_Mail_Exception(sprintf(_('"%s" is not a valid e-mail address'), $to));
        } // if
        if (!filter_var($this->_fromAddress, FILTER_VALIDATE_EMAIL)) {
            throw new Services_Mail_Exception(_('Set a valid sender e-mail address in Admin > E-mail first'));
        } // if

        $mail = $this->build($withLog);
        try {
            $mail->setFrom($this->_fromAddress, $this->_config['from_name']);
            $mail->addAddress($to);
            $mail->Subject = $this->oneLine($subject);
            $mail->isHTML(true);
            $mail->Body = ($htmlBody !== null) ? $htmlBody : self::htmlLayout($subject, $textBody, $this->_siteUrl);
            $mail->AltBody = $textBody;
            $mail->send();
        } catch (PHPMailerException $e) {
            throw new Services_Mail_Exception($this->explain($mail->ErrorInfo ?: $e->getMessage()), 0, $e);
        } finally {
            $mail->smtpClose();
        } // finally

        return true;
    }

    private function build($withLog)
    {
        $c = $this->_config;
        $mail = new PHPMailer(true);
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;
        $mail->XMailer = ' ';
        $mail->Timeout = $c['timeout'];
        $siteHost = (string) parse_url($this->_siteUrl, PHP_URL_HOST);
        if ($siteHost !== '' && $siteHost !== 'mijnuniekeservernaam') {
            $mail->Hostname = $siteHost;
        } // if

        if ($c['use']) {
            $mail->isSMTP();
            $mail->Host = $c['host'];
            $mail->Port = $c['port'];
            $mail->SMTPKeepAlive = false;
            // PHPMailer waits up to 5 minutes for a silent server; keep it to our timeout
            $mail->getSMTPInstance()->Timelimit = $c['timeout'];
            if ($c['encryption'] === self::ENC_SMTPS) {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                $mail->SMTPAutoTLS = false;
            } elseif ($c['encryption'] === self::ENC_STARTTLS) {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->SMTPAutoTLS = true;
            } else {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            } // else

            $mail->SMTPOptions = ['ssl' => [
                'verify_peer'       => $c['verify_cert'],
                'verify_peer_name'  => $c['verify_cert'],
                'allow_self_signed' => !$c['verify_cert'],
                'crypto_method'     => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0),
            ]];

            $mail->SMTPAuth = $c['auth'];
            if ($c['auth']) {
                $mail->Username = $c['user'];
                $mail->Password = $c['pass'];
            } // if

            if ($withLog) {
                $mail->SMTPDebug = SMTP::DEBUG_SERVER;
                $mail->Debugoutput = function ($line) {
                    $this->addLog($line);
                };
            } // if
        } else {
            $mail->isMail();
        } // else

        if (($c['dkim_domain'] !== '') && ($c['dkim_selector'] !== '') && ($c['dkim_key'] !== '')) {
            $mail->DKIM_domain = $c['dkim_domain'];
            $mail->DKIM_selector = $c['dkim_selector'];
            $mail->DKIM_private_string = $c['dkim_key'];
            $mail->DKIM_passphrase = $c['dkim_pass'];
            $mail->DKIM_identity = $this->_fromAddress;
        } // if

        return $mail;
    }

    /*
     * Keep the SMTP log readable without leaking the login
     */
    private function addLog($line)
    {
        $line = rtrim((string) $line);
        if ($line === '') {
            return;
        } // if
        $prev = end($this->_log);
        if (preg_match('/^CLIENT -> SERVER: AUTH (PLAIN|XOAUTH2) /i', $line)) {
            $line = preg_replace('/^(CLIENT -> SERVER: AUTH \S+) .*/i', '$1 [hidden]', $line);
        } elseif ((strpos($line, 'CLIENT -> SERVER:') === 0) && is_string($prev) && preg_match('/^SERVER -> CLIENT: 334/', $prev)) {
            $line = 'CLIENT -> SERVER: [hidden]';
        } // elseif
        if (count($this->_log) < 200) {
            $this->_log[] = $line;
        } // if
    }

    private function oneLine($text)
    {
        return trim(preg_replace('/[\r\n]+/', ' ', (string) $text));
    }

    /*
     * Translate the usual PHPMailer errors into a hint the admin can act on
     */
    private function explain($error)
    {
        // drop the noise PHPMailer adds after a failed connection, and any binary garbage
        $error = preg_replace('/\s*SMTP server error: QUIT command failed.*$/s', '', (string) $error);
        $error = preg_replace('/[^\P{C}\n]+/u', '', mb_scrub($error, 'UTF-8'));
        $error = trim(preg_replace('/\s+/', ' ', $error));
        if (strlen($error) > 400) {
            $error = substr($error, 0, 400).'…';
        } // if
        $hint = '';
        if (stripos($error, 'connect()') !== false || stripos($error, 'Failed to connect') !== false) {
            $hint = _('Check the host name, the port and whether this server may make outgoing connections on that port.');
        } elseif (stripos($error, 'authenticate') !== false) {
            $hint = _('The server refused the username or password.');
        } elseif (stripos($error, 'certificate') !== false || stripos($error, 'SSL') !== false || stripos($error, 'TLS') !== false) {
            $hint = _('The secure connection failed. Check the encryption type and port (STARTTLS usually 587, SSL/TLS 465), or the certificate.');
        } elseif (stripos($error, 'Could not instantiate mail function') !== false) {
            $hint = _('PHP\'s mail() function does not work on this server. Turn on SMTP.');
        } // elseif

        return trim($error.' '.$hint);
    }

    /*
     * A simple, client-safe HTML layout in the Spotweb house style.
     * Lines that are only a URL become a button.
     */
    public static function htmlLayout($title, $text, $siteUrl = '')
    {
        $esc = function ($s) {
            return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        };
        $paragraphs = preg_split("/\n\s*\n/", trim(str_replace("\r", '', (string) $text)));
        $html = '';
        foreach ($paragraphs as $p) {
            $p = trim($p);
            if (preg_match('#^https?://\S+$#', $p)) {
                $html .= '<p style="margin:24px 0"><a href="'.$esc($p).'" style="display:inline-block;background:#6D5DF5;color:#ffffff;text-decoration:none;font-weight:600;padding:12px 20px;border-radius:8px">'.$esc(_('Open link')).'</a></p>'
                       .'<p style="margin:0 0 16px;font-size:12px;color:#5B6676;word-break:break-all">'.$esc($p).'</p>';
            } else {
                $line = nl2br($esc($p), false);
                $line = preg_replace('#(https?://[^\s<]+)#', '<a href="$1" style="color:#4B3CC9">$1</a>', $line);
                $html .= '<p style="margin:0 0 16px">'.$line.'</p>';
            } // else
        } // foreach

        $footer = ($siteUrl !== '') ? '<a href="'.$esc($siteUrl).'" style="color:#5B6676">'.$esc(preg_replace('#^https?://#', '', rtrim($siteUrl, '/'))).'</a>' : 'Spotweb';

        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$esc($title).'</title></head>'
            .'<body style="margin:0;padding:0;background:#F5F6F9">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F5F6F9;padding:32px 16px"><tr><td align="center">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border:1px solid #DDE2EA;border-radius:12px">'
            .'<tr><td style="padding:20px 28px;border-bottom:1px solid #DDE2EA;font:700 18px Arial,Helvetica,sans-serif;color:#111827"><span style="color:#6D5DF5">&#9679;</span> Spotweb</td></tr>'
            .'<tr><td style="padding:28px;font:15px/1.55 Arial,Helvetica,sans-serif;color:#111827">'
            .'<h1 style="margin:0 0 20px;font-size:20px;line-height:1.3">'.$esc($title).'</h1>'.$html
            .'</td></tr></table>'
            .'<p style="font:12px Arial,Helvetica,sans-serif;color:#5B6676;margin:16px 0 0">'.$footer.'</p>'
            .'</td></tr></table></body></html>';
    }
} // Services_Mail_Sender
