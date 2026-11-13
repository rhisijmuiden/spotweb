<?php

use PHPUnit\Framework\TestCase;

class BootstrapDbSettingsTest extends TestCase
{
    private $_vars = ['SPOTWEB_DBSETTINGS', 'SPOTWEB_DB_ENGINE', 'SPOTWEB_DB_HOST', 'SPOTWEB_DB_NAME', 'SPOTWEB_DB_USER', 'SPOTWEB_DB_PASS'];

    protected function tearDown(): void
    {
        foreach ($this->_vars as $var) {
            putenv($var);
        } // foreach
    }

    public function testExplicitFileWins()
    {
        $file = tempnam(sys_get_temp_dir(), 'dbs');
        file_put_contents($file, "<?php\n\$dbsettings = ['engine' => 'pdo_sqlite', 'host' => '', 'dbname' => '/tmp/x.sqlite3', 'user' => '', 'pass' => ''];\n");
        putenv('SPOTWEB_DBSETTINGS='.$file);

        $this->assertSame($file, Bootstrap::dbSettingsFile());
        $this->assertSame('/tmp/x.sqlite3', Bootstrap::loadDbSettings()['dbname']);
        unlink($file);
    }

    public function testEnvironmentVariablesWhenNoFile()
    {
        if (Bootstrap::dbSettingsFile() !== null) {
            $this->markTestSkipped('a dbsettings.inc.php exists in this checkout');
        } // if

        putenv('SPOTWEB_DB_ENGINE=pdo_mysql');
        putenv('SPOTWEB_DB_HOST=db');
        putenv('SPOTWEB_DB_NAME=spotweb');
        $settings = Bootstrap::loadDbSettings();

        $this->assertSame('pdo_mysql', $settings['engine']);
        $this->assertSame('db', $settings['host']);
        $this->assertSame('spotweb', $settings['dbname']);
        $this->assertSame('', $settings['pass']);
    }

    public function testNothingConfigured()
    {
        if (Bootstrap::dbSettingsFile() !== null) {
            $this->markTestSkipped('a dbsettings.inc.php exists in this checkout');
        } // if

        $this->assertNull(Bootstrap::loadDbSettings());
    }
}
