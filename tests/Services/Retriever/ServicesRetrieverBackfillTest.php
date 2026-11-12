<?php

use PHPUnit\Framework\TestCase;

class ServicesRetrieverBackfillTest extends TestCase
{
    /*
     * A fake group: article n was posted at 1,600,000,000 + n * 60,
     * with a gap of missing articles in the middle
     */
    private function dateNear()
    {
        return function ($nr) {
            if ($nr > 2000000) {
                return null;
            }
            if ($nr >= 900000 && $nr < 950000) {
                $nr = 950000;
            }

            return ['nr' => $nr, 'stamp' => 1600000000 + $nr * 60];
        };
    }

    public function testFindsTheArticleNearTheRequestedDate()
    {
        $target = 1600000000 + 1234567 * 60;
        $found = Services_Retriever_Backfill::searchFirstArticle(1, 2000000, $target, $this->dateNear());

        $this->assertLessThanOrEqual(1234567, $found);
        $this->assertGreaterThan(1234567 - 600, $found);
    }

    public function testDateBeforeTheGroupStartsGivesTheFirstArticle()
    {
        $this->assertSame(1, Services_Retriever_Backfill::searchFirstArticle(1, 2000000, 1000, $this->dateNear()));
    }

    public function testGapsInTheGroupAreHandled()
    {
        $target = 1600000000 + 920000 * 60;
        $found = Services_Retriever_Backfill::searchFirstArticle(1, 2000000, $target, $this->dateNear());

        $this->assertLessThanOrEqual(950000, $found);
        $this->assertGreaterThan(899000, $found);
    }
}
