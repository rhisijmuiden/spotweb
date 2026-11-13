<?php

use PHPUnit\Framework\TestCase;

class ServicesMailSenderTest extends TestCase
{
    public function testOldSettingsGetModernDefaults()
    {
        $smtp = Services_Mail_Sender::normalize(['use' => true, 'host' => 'smtp.example.com', 'user' => 'a', 'pass' => 'b', 'port' => '587']);
        $this->assertSame('starttls', $smtp['encryption']);
        $this->assertTrue($smtp['auth']);
        $this->assertTrue($smtp['verify_cert']);
        $this->assertSame(587, $smtp['port']);
    }

    public function testEmptyPasswordKeepsTheStoredOne()
    {
        $form = ['use' => 'on', 'host' => 'smtp.example.com', 'port' => '', 'encryption' => 'smtps', 'auth' => 'on', 'user' => 'a', 'pass' => ''];
        list($smtp, $errors) = Services_Mail_Sender::fromForm($form, ['pass' => 'secret']);
        $this->assertSame([], $errors);
        $this->assertSame('secret', $smtp['pass']);
        $this->assertSame(465, $smtp['port']);
    }

    public function testInvalidFormIsRejected()
    {
        $form = ['use' => 'on', 'host' => '', 'port' => '70000', 'encryption' => 'rot13', 'auth' => 'on', 'from_name' => "x\r\nBcc: y"];
        list(, $errors) = Services_Mail_Sender::fromForm($form, []);
        $this->assertGreaterThanOrEqual(4, count($errors));
    }

    public function testRemovingDkimClearsAllDkimFields()
    {
        $current = ['dkim_domain' => 'example.com', 'dkim_selector' => 's', 'dkim_key' => 'KEY', 'dkim_pass' => 'p'];
        list($smtp, $errors) = Services_Mail_Sender::fromForm(['dkim_domain' => 'example.com', 'dkim_selector' => 's', 'clear_dkim' => 'on'], $current);
        $this->assertSame([], $errors);
        $this->assertSame('', $smtp['dkim_key']);
        $this->assertSame('', $smtp['dkim_domain']);
    }

    public function testHtmlLayoutEscapesAndMakesAButton()
    {
        $html = Services_Mail_Sender::htmlLayout('<b>Hi</b>', "Hello <you>\n\nhttps://example.com/?a=1&b=2");
        $this->assertStringNotContainsString('<b>Hi</b>', $html);
        $this->assertStringContainsString('&lt;you&gt;', $html);
        $this->assertStringContainsString('href="https://example.com/?a=1&amp;b=2"', $html);
    }

    public function testInvalidRecipientThrows()
    {
        $this->expectException(Services_Mail_Exception::class);
        (new Services_Mail_Sender([], 'from@example.com'))->send('not-an-address', 'x', 'y');
    }
}
