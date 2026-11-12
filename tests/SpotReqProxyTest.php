<?php

use PHPUnit\Framework\TestCase;

class SpotReqProxyTest extends TestCase
{
    private $server;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR'],
            $_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['HTTP_CF_VISITOR'], $_SERVER['HTTP_X_REAL_IP'],
            $_SERVER['HTTP_X_FORWARDED_SSL']);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
    }

    public function testIpInRange()
    {
        $this->assertTrue(SpotReq::ipInRange('104.16.1.1', '104.16.0.0/13'));
        $this->assertFalse(SpotReq::ipInRange('104.24.0.1', '104.16.0.0/13'));
        $this->assertTrue(SpotReq::ipInRange('2606:4700:10::1', '2606:4700::/32'));
        $this->assertFalse(SpotReq::ipInRange('10.0.0.1', '2606:4700::/32'));
        $this->assertTrue(SpotReq::ipInRange('172.31.255.255', '172.16.0.0/12'));
    }

    public function testDirectClientCannotSpoofHeaders()
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '1.2.3.4';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '5.6.7.8';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        $this->assertSame('203.0.113.7', SpotReq::getClientIp());
        $this->assertFalse(SpotReq::isSecureRequest());
    }

    public function testCloudflareVisitorIsUsed()
    {
        $_SERVER['REMOTE_ADDR'] = '172.68.10.20';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.23';
        $_SERVER['HTTP_CF_VISITOR'] = '{"scheme":"https"}';

        $this->assertSame('198.51.100.23', SpotReq::getClientIp());
        $this->assertTrue(SpotReq::isSecureRequest());
        $this->assertSame('https', SpotReq::getRequestProtocol());
    }

    public function testLocalReverseProxyForwardedFor()
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.1.1.1, 198.51.100.40, 10.0.0.2';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        $this->assertSame('198.51.100.40', SpotReq::getClientIp());
        $this->assertTrue(SpotReq::isSecureRequest());
    }
}
