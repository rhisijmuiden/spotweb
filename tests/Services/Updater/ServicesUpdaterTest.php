<?php

use PHPUnit\Framework\TestCase;

class ServicesUpdaterTest extends TestCase
{
    private $_dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->_dirs as $dir) {
            Services_Updater_Archive::removeTree($dir);
        } // foreach
    }

    private function tempDir()
    {
        $dir = sys_get_temp_dir().'/swupd-'.bin2hex(random_bytes(5));
        mkdir($dir, 0777, true);
        $this->_dirs[] = $dir;

        return $dir;
    }

    private function put($root, $rel, $content)
    {
        if (!is_dir(dirname($root.'/'.$rel))) {
            mkdir(dirname($root.'/'.$rel), 0777, true);
        } // if
        file_put_contents($root.'/'.$rel, $content);
    }

    private function spotwebSkeleton($root, $marker)
    {
        $this->put($root, 'index.php', "<?php // $marker\n");
        $this->put($root, 'lib/Bootstrap.php', "<?php // $marker\n");
        $this->put($root, 'vendor/autoload.php', "<?php // $marker\n");
    }

    public function testReadsBranchAndCommitFromLooseRef()
    {
        $root = $this->tempDir();
        $sha = str_repeat('ab', 20);
        $this->put($root, '.git/HEAD', "ref: refs/heads/develop\n");
        $this->put($root, '.git/refs/heads/develop', $sha."\n");

        $source = (new Services_Updater_Installed($root))->source();
        $this->assertSame($sha, $source['commit']);
        $this->assertSame('develop', $source['branch']);
        $this->assertSame('git', $source['from']);
    }

    public function testReadsPackedRefsAndDetachedHead()
    {
        $root = $this->tempDir();
        $sha = str_repeat('cd', 20);
        $this->put($root, '.git/HEAD', "ref: refs/heads/main\n");
        $this->put($root, '.git/packed-refs', "# pack-refs with: peeled fully-peeled sorted\n".str_repeat('11', 20)." refs/heads/develop\n".$sha." refs/heads/main\n");
        $this->assertSame($sha, (new Services_Updater_Installed($root))->source()['commit']);

        $this->put($root, '.git/HEAD', strtoupper($sha)."\n");
        $source = (new Services_Updater_Installed($root))->source();
        $this->assertSame($sha, $source['commit']);
        $this->assertSame('', $source['branch']);
    }

    public function testNewestOfStampAndGitWins()
    {
        $root = $this->tempDir();
        $gitSha = str_repeat('aa', 20);
        $stampSha = str_repeat('bb', 20);
        $this->put($root, '.git/HEAD', "ref: refs/heads/develop\n");
        $this->put($root, '.git/refs/heads/develop', $gitSha);
        touch($root.'/.git/HEAD', time() - 100);

        $installed = new Services_Updater_Installed($root);
        $this->assertTrue($installed->writeStamp($stampSha, 'main', 123));
        $this->assertSame($stampSha, $installed->source()['commit']);
        $this->assertSame('main', $installed->source()['branch']);

        // a later git pull makes git the newest again
        touch($root.'/.git/HEAD', time() + 100);
        $this->assertSame($gitSha, $installed->source()['commit']);
    }

    public function testUnknownWithoutGitOrStamp()
    {
        $source = (new Services_Updater_Installed($this->tempDir()))->source();
        $this->assertSame('', $source['commit']);
        $this->assertSame('', $source['from']);
    }

    public function testBlobShaMatchesGit()
    {
        $root = $this->tempDir();
        $this->put($root, 'a.txt', "hello\n");
        // git hash-object of "hello\n"
        $this->assertSame('ce013625030ba8dba906f756967f9e9ca394464a', Services_Updater_Installed::blobSha($root.'/a.txt'));
    }

    public function testInstallReplacesChangedFilesAndKeepsSettings()
    {
        $root = $this->tempDir();
        $staging = $this->tempDir();
        $work = $this->tempDir();

        $this->spotwebSkeleton($root, 'old');
        $this->put($root, 'same.php', 'same');
        $this->put($root, 'dbsettings.inc.php', 'MY DATABASE');
        $this->put($root, 'cache/keep.txt', 'cache');
        $this->put($root, 'gone.php', 'old content');
        $this->put($root, 'edited-locally.php', 'my edit');

        $this->spotwebSkeleton($staging, 'new');
        $this->put($staging, 'same.php', 'same');
        $this->put($staging, 'lib/New/Added.php', 'added');
        $this->put($staging, 'dbsettings.inc.php', 'FROM ARCHIVE');
        $this->put($staging, 'cache/keep.txt', 'from archive');

        $removed = [
            'gone.php'           => sha1('blob '.strlen('old content')."\0".'old content'),
            'edited-locally.php' => sha1('blob 8'."\0".'original'),
            '../outside.php'     => 'x',
        ];

        $phases = [];
        $archive = new Services_Updater_Archive($root, $work, function ($phase) use (&$phases) { $phases[] = $phase; });
        $result = $archive->installFrom($staging, $removed);

        $this->assertSame("<?php // new\n", file_get_contents($root.'/index.php'));
        $this->assertSame('added', file_get_contents($root.'/lib/New/Added.php'));
        $this->assertSame('MY DATABASE', file_get_contents($root.'/dbsettings.inc.php'));
        $this->assertSame('cache', file_get_contents($root.'/cache/keep.txt'));
        $this->assertFalse(file_exists($root.'/gone.php'));
        $this->assertFileExists($root.'/edited-locally.php');
        $this->assertSame(['changed' => 3, 'added' => 1, 'removed' => 1], $result);
        $this->assertSame("<?php // old\n", file_get_contents($work.'/backup/index.php'));
        $this->assertContains('copy', $phases);
        $this->assertSame([], glob($root.'/*.swupdate-*'));
    }

    public function testRefusesIncompleteDownload()
    {
        $root = $this->tempDir();
        $staging = $this->tempDir();
        $this->spotwebSkeleton($root, 'old');
        $this->put($staging, 'index.php', 'new');

        $this->expectException(RuntimeException::class);
        (new Services_Updater_Archive($root, $this->tempDir()))->installFrom($staging);
    }

    public function testNothingChangesWhenAFileIsNotWritable()
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root can write everywhere');
        } // if
        $root = $this->tempDir();
        $staging = $this->tempDir();
        $this->spotwebSkeleton($root, 'old');
        $this->put($root, 'locked/file.php', 'old');
        $this->spotwebSkeleton($staging, 'new');
        $this->put($staging, 'locked/file.php', 'new');
        chmod($root.'/locked', 0555);

        try {
            (new Services_Updater_Archive($root, $this->tempDir()))->installFrom($staging);
            $this->fail('expected an exception');
        } catch (RuntimeException $x) {
            $this->assertStringContainsString('locked/file.php', $x->getMessage());
        } finally {
            chmod($root.'/locked', 0755);
        } // finally
        $this->assertSame("<?php // old\n", file_get_contents($root.'/index.php'));
    }

    public function testExtractStripsTopFolderAndRejectsTraversal()
    {
        $dir = $this->tempDir();
        $zipFile = $dir.'/ok.zip';
        $zip = new ZipArchive();
        $zip->open($zipFile, ZipArchive::CREATE);
        $zip->addFromString('owner-repo-abc1234/index.php', 'idx');
        $zip->addFromString('owner-repo-abc1234/lib/Bootstrap.php', 'boot');
        $zip->close();

        $archive = new Services_Updater_Archive($this->tempDir(), $this->tempDir());
        $archive->extract($zipFile, $dir.'/out');
        $this->assertSame('idx', file_get_contents($dir.'/out/index.php'));
        $this->assertSame('boot', file_get_contents($dir.'/out/lib/Bootstrap.php'));

        $evilFile = $dir.'/evil.zip';
        $zip = new ZipArchive();
        $zip->open($evilFile, ZipArchive::CREATE);
        $zip->addFromString('top/index.php', 'idx');
        $zip->addFromString('top/../../evil.php', 'evil');
        $zip->close();

        $this->expectException(RuntimeException::class);
        $archive->extract($evilFile, $dir.'/out2');
    }

    public function testProtectedAndSafePaths()
    {
        $this->assertTrue(Services_Updater_Archive::isProtected('ownsettings.php'));
        $this->assertTrue(Services_Updater_Archive::isProtected('cache/x/y'));
        $this->assertTrue(Services_Updater_Archive::isProtected('.git/config'));
        $this->assertFalse(Services_Updater_Archive::isProtected('lib/cache/Thing.php'));
        $this->assertFalse(Services_Updater_Archive::safeRelative('../x'));
        $this->assertFalse(Services_Updater_Archive::safeRelative('/etc/passwd'));
        $this->assertFalse(Services_Updater_Archive::safeRelative('a//b'));
        $this->assertTrue(Services_Updater_Archive::safeRelative('lib/a.php'));
    }

    public function testReadsVersionsFromBootstrap()
    {
        class_exists('Bootstrap');
        $found = Services_Updater_Check::versionsFromBootstrap(file_get_contents(__DIR__.'/../../../lib/Bootstrap.php'));
        $this->assertSame(['schema' => SPOTDB_SCHEMA_VERSION, 'settings' => SPOTWEB_SETTINGS_VERSION, 'security' => SPOTWEB_SECURITY_VERSION], $found);
    }

    public function testFilesMatchDetectsADeployByAnotherTool()
    {
        $root = $this->tempDir();
        $this->put($root, 'a.php', 'new a');
        $installed = new Services_Updater_Installed($root);
        $check = new Services_Updater_Check($this->createMock(dbeng_abs::class), $installed);

        $compare = ['files_complete' => true, 'files' => [
            ['filename' => 'a.php', 'status' => 'modified', 'sha' => sha1('blob 5'."\0".'new a'), 'previous' => ''],
            ['filename' => 'b.php', 'status' => 'removed', 'sha' => 'x', 'previous' => ''],
        ]];
        $this->assertTrue($check->filesMatch($compare));

        $this->put($root, 'b.php', 'still here');
        $this->assertFalse($check->filesMatch($compare));

        $compare['files_complete'] = false;
        unlink($root.'/b.php');
        $this->assertFalse($check->filesMatch($compare));
    }

    public function testBranchNamesAreValidated()
    {
        $this->assertTrue(Services_Updater_GitHub::validBranch('develop'));
        $this->assertTrue(Services_Updater_GitHub::validBranch('claude/feature-x'));
        $this->assertFalse(Services_Updater_GitHub::validBranch('a..b'));
        $this->assertFalse(Services_Updater_GitHub::validBranch('x y'));
        $this->assertFalse(Services_Updater_GitHub::validRepo('owner/repo/extra'));
        $this->assertTrue(Services_Updater_GitHub::validRepo('rhisijmuiden/spotweb'));
    }

    public function testJobKeyIsCheckedAgainstItsHash()
    {
        $key = bin2hex(random_bytes(16));
        $state = ['status' => 'running', 'keyhash' => hash('sha256', $key)];
        $this->assertTrue(Services_Updater_Job::keyMatches($state, $key));
        $this->assertFalse(Services_Updater_Job::keyMatches($state, str_repeat('0', 32)));
        $this->assertFalse(Services_Updater_Job::keyMatches($state, 'short'));
        $this->assertFalse(Services_Updater_Job::keyMatches(null, $key));
        $this->assertArrayNotHasKey('keyhash', Services_Updater_Job::forDisplay($state));
    }
}
