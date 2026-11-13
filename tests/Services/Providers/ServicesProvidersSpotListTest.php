<?php

use PHPUnit\Framework\TestCase;

class ServicesProvidersSpotListTest extends TestCase
{
    private function search($filter, $sortField = 's.stamp', $direction = 'DESC')
    {
        return [
            'filter'           => $filter,
            'additionalFields' => [],
            'additionalTables' => [],
            'additionalJoins'  => [],
            'sortFields'       => [['field' => $sortField, 'direction' => $direction, 'autoadded' => true, 'friendlyname' => null]],
        ];
    }

    private function fakeDao(array $answers)
    {
        $dao = $this->createMock(Dao_Spot::class);
        $calls = new ArrayObject();
        $dao->method('getSpots')->willReturnCallback(function ($userId, $pageNr, $limit, $parsedSearch) use (&$answers, $calls) {
            $calls[] = $parsedSearch;

            return array_shift($answers);
        });

        return [$dao, $calls];
    }

    public function testWindowsOnlyForDateSortedSearchesWithoutText()
    {
        $this->assertTrue(Services_Providers_SpotList::canUseWindows($this->search("((s.category = 0) AND (s.subcatz = 'z1|'))")));
        $this->assertTrue(Services_Providers_SpotList::canUseWindows($this->search('')));
        $this->assertFalse(Services_Providers_SpotList::canUseWindows($this->search("MATCH(s.title) AGAINST ('+river' IN BOOLEAN MODE)")));
        $this->assertFalse(Services_Providers_SpotList::canUseWindows($this->search("s.title LIKE '%river%'")));
        $this->assertFalse(Services_Providers_SpotList::canUseWindows($this->search('', 's.stamp', 'ASC')));
        $this->assertFalse(Services_Providers_SpotList::canUseWindows($this->search('', 's.title', 'DESC')));
    }

    public function testStopsAtTheFirstWindowWithEnoughSpots()
    {
        $now = 2000000000;
        list($dao, $calls) = $this->fakeDao([['list' => [1, 2], 'hasmore' => true]]);
        $svc = new Services_Providers_SpotList($dao);

        $result = $svc->fetchNewestSpotList(3, 0, 2, $this->search('(s.category = 1)'), $now);

        $this->assertSame([1, 2], $result['list']);
        $this->assertCount(1, $calls);
        $this->assertSame('((s.category = 1)) AND (s.reversestamp <= '.(-($now - 86400)).')', $calls[0]['filter']);
    }

    public function testFallsBackToTheWholeDatabase()
    {
        $now = 2000000000;
        $few = ['list' => [1], 'hasmore' => false];
        list($dao, $calls) = $this->fakeDao([$few, $few, $few, ['list' => [1, 2], 'hasmore' => false]]);
        $svc = new Services_Providers_SpotList($dao);

        $result = $svc->fetchNewestSpotList(3, 0, 5, $this->search('(s.category = 1)'), $now);

        $this->assertSame([1, 2], $result['list']);
        $this->assertCount(4, $calls);
        $this->assertSame('(s.category = 1)', $calls[3]['filter']);
        $this->assertTrue($calls[3]['avoidDateIndexWalk']);
    }

    public function testDeeperPagesKeepTheNormalPlan()
    {
        $few = ['list' => [], 'hasmore' => false];
        list($dao, $calls) = $this->fakeDao([$few, $few, $few, $few]);
        $svc = new Services_Providers_SpotList($dao);

        $svc->fetchNewestSpotList(3, 2, 5, $this->search(''), 2000000000);

        $this->assertCount(4, $calls);
        $this->assertSame('', $calls[3]['filter']);
        $this->assertArrayNotHasKey('avoidDateIndexWalk', $calls[3]);
    }

    public function testTextSearchesSkipTheWindows()
    {
        list($dao, $calls) = $this->fakeDao([['list' => [], 'hasmore' => false]]);
        $svc = new Services_Providers_SpotList($dao);
        $search = $this->search("MATCH(s.title) AGAINST ('+x' IN BOOLEAN MODE)");

        $svc->fetchNewestSpotList(3, 0, 5, $search);

        $this->assertCount(1, $calls);
        $this->assertSame($search, $calls[0]);
    }
}
