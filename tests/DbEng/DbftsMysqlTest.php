<?php

use PHPUnit\Framework\TestCase;

class DbftsMysqlTestWrapper extends dbfts_mysql
{
    public function words($s)
    {
        return $this->splitWords($s);
    }

    public function requiredWords($value)
    {
        return $this->getRequiredIndexWords($value, 3, ['the', 'of']);
    }

    public function rewrite($value)
    {
        return $this->rewriteRequiredPhrases('s.title', $value, 3, ['the', 'of']);
    }
}

class DbftsMysqlTest extends TestCase
{
    private function fts()
    {
        $db = $this->getMockBuilder(dbeng_pdo_mysql::class)
            ->disableOriginalConstructor()
            ->setMethods(['safe'])
            ->getMock();
        $db->method('safe')->willReturnCallback(function ($s) {
            return "'".addslashes($s)."'";
        });

        return new DbftsMysqlTestWrapper($db);
    }

    public function testSplitWordsKeepsSeparatePhrasesApart()
    {
        $this->assertSame(
            ['+', '"Dark Night"', '+', '"Season 2"'],
            $this->fts()->words('+"Dark Night" +"Season 2"')
        );
    }

    public function testRequiredWordsSkipShortWordsAndStopwords()
    {
        $this->assertSame(
            ['dark', 'night', 'season'],
            $this->fts()->requiredWords('+"Dark Night" +"Season 2" optional')
        );
        $this->assertSame(['last', 's02*'], $this->fts()->requiredWords('+"The Last of Us" +S02*'));
    }

    public function testRequiredPhraseIsRewrittenToWordsPlusLike()
    {
        list($value, $likes) = $this->fts()->rewrite('+"Dark Night" +S02*');

        $this->assertSame('+(+Dark +Night) +S02*', $value);
        $this->assertSame([" s.title LIKE '%Dark_Night%'"], $likes);
    }

    public function testPhraseWithoutIndexableWordsIsKept()
    {
        list($value, $likes) = $this->fts()->rewrite('+"of 2"');

        $this->assertSame('+"of 2"', $value);
        $this->assertSame([], $likes);
    }
}
