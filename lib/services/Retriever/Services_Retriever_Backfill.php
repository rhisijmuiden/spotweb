<?php

/*
 * Fetches spots that are older than the oldest spot in the database, back
 * to the "retrieve spots newer than" date, without emptying the database.
 *
 * Use it after moving that date further into the past:
 *
 *  1. find the article whose date is just after the new start date
 *     (binary search on the news server, a few small XOVER calls);
 *  2. find the article of the oldest spot we already have;
 *  3. retrieve only that range with the normal spot processing, which
 *     skips spots that are already stored.
 *
 * The pointer of the normal retrieve (last article / message id) is put
 * back afterwards, and nothing newer is pruned.
 */
class Services_Retriever_Backfill extends Services_Retriever_Spots
{
    /** @var int */
    private $_startStamp;
    /** @var int */
    private $_endStamp;

    public function __construct(Dao_Factory $daoFactory, Services_Settings_Container $settings, $startStamp, $endStamp)
    {
        // force = false; retro = false (we only want headers we do not have yet)
        parent::__construct($daoFactory, $settings, false, false);
        $this->_startStamp = (int) $startStamp;
        $this->_endStamp = (int) $endStamp;
    }

    /*
     * Date of the first header at or after $articleNr, or null when the
     * window holds no headers
     */
    private function dateNear($articleNr, $last)
    {
        $hdrList = $this->_svcNntpTextPipelined->getOverview($articleNr, min($articleNr + 200, $last));
        foreach ($hdrList as $hdr) {
            $stamp = isset($hdr['Date']) ? strtotime($hdr['Date']) : false;
            if ($stamp !== false) {
                return ['nr' => (int) $hdr['Number'], 'stamp' => $stamp];
            } // if
        } // foreach

        return null;
    }

    /*
     * Lowest article number whose date is at or after $stamp
     */
    public function findArticleByDate($stamp)
    {
        $range = $this->groupRange();

        return self::searchFirstArticle($range['first'], $range['last'], $stamp, function ($articleNr) use ($range) {
            return $this->dateNear($articleNr, $range['last']);
        });
    }

    /*
     * Binary search over article numbers. $dateNear($nr) returns
     * ['nr' => .., 'stamp' => ..] for the first header at or after $nr,
     * or null when there is none. About 30 lookups for any group size.
     */
    public static function searchFirstArticle($low, $high, $stamp, callable $dateNear)
    {
        while (($high - $low) > 500) {
            $mid = (int) floor(($low + $high) / 2);
            $near = $dateNear($mid);
            if ($near === null || $near['stamp'] >= $stamp) {
                $high = $mid;
            } else {
                $low = max($mid, (int) $near['nr']) + 1;
            } // else
        } // while

        return $low;
    }

    public function perform()
    {
        $range = $this->connect($this->getGroupName());

        $startArticle = $this->findArticleByDate($this->_startStamp);
        $endArticle = $this->findArticleByDate($this->_endStamp + 1);
        $this->displayStatus('progress', 'backfill '.$startArticle.' till '.$endArticle);

        // keep the pointer of the normal retrieve
        $savedArticleNr = $this->_usenetStateDao->getLastArticleNumber(Dao_UsenetState::State_Spots);
        $savedMessageId = $this->_usenetStateDao->getLastMessageId(Dao_UsenetState::State_Spots);

        try {
            $count = ($endArticle > $startArticle)
                ? $this->loopTillEnd($startArticle, $this->_settings->get('retrieve_increment'), $endArticle)
                : 0;
        } finally {
            $this->_usenetStateDao->setMaxArticleId(Dao_UsenetState::State_Spots, $savedArticleNr, $savedMessageId);
        } // finally

        $this->quit();

        return $count;
    }

    // perform
}
