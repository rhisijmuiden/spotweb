<?php

class dbfts_mysql extends dbfts_abs
{
    // LIST OF STOP WORDS: http://dev.mysql.com/doc/refman/5.0/en/fulltext-stopwords.html
    // Array copied from http://www.linein.org/blog/2008/11/10/mysql-full-text-stopwords-array/
    private $stop_words = ['a\'s', 'able', 'about', 'above', 'according', 'accordingly', 'across', 'actually',
        'after', 'afterwards', 'again', 'against', 'ain\'t', 'all', 'allow', 'allows', 'almost',
        'alone', 'along', 'already', 'also', 'although', 'always', 'am', 'among', 'amongst', 'an',
        'and', 'another', 'any', 'anybody', 'anyhow', 'anyone', 'anything', 'anyway', 'anyways',
        'anywhere', 'apart', 'appear', 'appreciate', 'appropriate', 'are', 'aren\'t', 'around', 'as',
        'aside', 'ask', 'asking', 'associated', 'at', 'available', 'away', 'awfully', 'be', 'became',
        'because', 'become', 'becomes', 'becoming', 'been', 'before', 'beforehand', 'behind', 'being',
        'believe', 'below', 'beside', 'besides', 'best', 'better', 'between', 'beyond', 'both', 'brief',
        'but', 'by', 'c\'mon', 'c\'s', 'came', 'can', 'can\'t', 'cannot', 'cant', 'cause', 'causes',
        'certain', 'certainly', 'changes', 'clearly', 'co', 'com', 'come', 'comes', 'concerning',
        'consequently', 'consider', 'considering', 'contain', 'containing', 'contains', 'corresponding',
        'could', 'couldn\'t', 'course', 'currently', 'definitely', 'described', 'despite', 'did', 'didn\'t',
        'different', 'do', 'does', 'doesn\'t', 'doing', 'don\'t', 'done', 'down', 'downwards', 'during',
        'each', 'edu', 'eg', 'eight', 'either', 'else', 'elsewhere', 'enough', 'entirely', 'especially',
        'et', 'etc', 'even', 'ever', 'every', 'everybody', 'everyone', 'everything', 'everywhere', 'ex',
        'exactly', 'example', 'except', 'far', 'few', 'fifth', 'first', 'five', 'followed', 'following',
        'follows', 'for', 'former', 'formerly', 'forth', 'four', 'from', 'further', 'furthermore', 'get',
        'gets', 'getting', 'given', 'gives', 'go', 'goes', 'going', 'gone', 'got', 'gotten', 'greetings',
        'had', 'hadn\'t', 'happens', 'hardly', 'has', 'hasn\'t', 'have', 'haven\'t', 'having', 'he',
        'he\'s', 'hello', 'help', 'hence', 'her', 'here', 'here\'s', 'hereafter', 'hereby', 'herein',
        'hereupon', 'hers', 'herself', 'hi', 'him', 'himself', 'his', 'hither', 'hopefully', 'how',
        'howbeit', 'however', 'i\'d', 'i\'ll', 'i\'m', 'i\'ve', 'ie', 'if', 'ignored', 'immediate',
        'in', 'inasmuch', 'inc', 'indeed', 'indicate', 'indicated', 'indicates', 'inner', 'insofar',
        'instead', 'into', 'inward', 'is', 'isn\'t', 'it', 'it\'d', 'it\'ll', 'it\'s', 'its', 'itself',
        'just', 'keep', 'keeps', 'kept', 'know', 'knows', 'known', 'last', 'lately', 'later', 'latter',
        'latterly', 'least', 'less', 'lest', 'let', 'let\'s', 'like', 'liked', 'likely', 'little',
        'look', 'looking', 'looks', 'ltd', 'mainly', 'many', 'may', 'maybe', 'me', 'mean', 'meanwhile',
        'merely', 'might', 'more', 'moreover', 'most', 'mostly', 'much', 'must', 'my', 'myself', 'name',
        'namely', 'nd', 'near', 'nearly', 'necessary', 'need', 'needs', 'neither', 'never', 'nevertheless',
        'new', 'next', 'nine', 'no', 'nobody', 'non', 'none', 'noone', 'nor', 'normally', 'not', 'nothing',
        'novel', 'now', 'nowhere', 'obviously', 'of', 'off', 'often', 'oh', 'ok', 'okay', 'old', 'on', 'once',
        'one', 'ones', 'only', 'onto', 'or', 'other', 'others', 'otherwise', 'ought', 'our', 'ours',
        'ourselves', 'out', 'outside', 'over', 'overall', 'own', 'particular', 'particularly', 'per',
        'perhaps', 'placed', 'please', 'plus', 'possible', 'presumably', 'probably', 'provides', 'que',
        'quite', 'qv', 'rather', 'rd', 're', 'really', 'reasonably', 'regarding', 'regardless', 'regards',
        'relatively', 'respectively', 'right', 'said', 'same', 'saw', 'say', 'saying', 'says', 'second',
        'secondly', 'see', 'seeing', 'seem', 'seemed', 'seeming', 'seems', 'seen', 'self', 'selves',
        'sensible', 'sent', 'serious', 'seriously', 'seven', 'several', 'shall', 'she', 'should', 'shouldn\'t',
        'since', 'six', 'so', 'some', 'somebody', 'somehow', 'someone', 'something', 'sometime', 'sometimes',
        'somewhat', 'somewhere', 'soon', 'sorry', 'specified', 'specify', 'specifying', 'still', 'sub', 'such',
        'sup', 'sure', 't\'s', 'take', 'taken', 'tell', 'tends', 'th', 'than', 'thank', 'thanks', 'thanx',
        'that', 'that\'s', 'thats', 'the', 'their', 'theirs', 'them', 'themselves', 'then', 'thence', 'there',
        'there\'s', 'thereafter', 'thereby', 'therefore', 'therein', 'theres', 'thereupon', 'these', 'they',
        'they\'d', 'they\'ll', 'they\'re', 'they\'ve', 'think', 'third', 'this', 'thorough', 'thoroughly',
        'those', 'though', 'three', 'through', 'throughout', 'thru', 'thus', 'to', 'together', 'too', 'took',
        'toward', 'towards', 'tried', 'tries', 'truly', 'try', 'trying', 'twice', 'two', 'un', 'under',
        'unfortunately', 'unless', 'unlikely', 'until', 'unto', 'up', 'upon', 'us', 'use', 'used',
        'useful', 'uses', 'using', 'usually', 'value', 'various', 'very', 'via', 'viz', 'vs', 'want',
        'wants', 'was', 'wasn\'t', 'way', 'we', 'we\'d', 'we\'ll', 'we\'re', 'we\'ve', 'welcome', 'well',
        'went', 'were', 'weren\'t', 'what', 'what\'s', 'whatever', 'when', 'whence', 'whenever', 'where',
        'where\'s', 'whereafter', 'whereas', 'whereby', 'wherein', 'whereupon', 'wherever', 'whether',
        'which', 'while', 'whither', 'who', 'who\'s', 'whoever', 'whole', 'whom', 'whose', 'why', 'will',
        'willing', 'wish', 'with', 'within', 'without', 'won\'t', 'wonder', 'would', 'would', 'wouldn\'t',
        'yes', 'yet', 'you', 'you\'d', 'you\'ll', 'you\'re', 'you\'ve', 'your', 'yours', 'yourself',
        'yourselves', 'zero', ];

    /*
     * InnoDB uses a much smaller default stopword list than MyISAM
     * (INFORMATION_SCHEMA.INNODB_FT_DEFAULT_STOPWORD)
     */
    private $innodb_stop_words = ['a', 'about', 'an', 'are', 'as', 'at', 'be', 'by', 'com', 'de', 'en',
        'for', 'from', 'how', 'i', 'in', 'is', 'it', 'la', 'of', 'on', 'or', 'that', 'the', 'this', 'to',
        'was', 'what', 'when', 'where', 'who', 'will', 'with', 'und', 'www', ];

    /*
     * Server FTS configuration, cached per process because it is the same
     * for every query and used to cost an extra roundtrip per search
     */
    private static $ftsConfig = null;

    /**
     * Returns the minimum indexed word length and the stopword list which
     * apply to the spots table. InnoDB and MyISAM use different settings.
     *
     * @return array [int minWordLen, array stopWords]
     */
    protected function getFtsConfig()
    {
        if (self::$ftsConfig === null) {
            $isInnoDb = true;
            $minWordLen = 3;

            try {
                $row = $this->_db->arrayQuery(
                    "SELECT ENGINE AS engine, @@innodb_ft_min_token_size AS innodb_len, @@ft_min_word_len AS myisam_len
                       FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'spots'"
                );
                if (!empty($row)) {
                    $isInnoDb = (strcasecmp((string) $row[0]['engine'], 'MyISAM') !== 0);
                    $minWordLen = (int) ($isInnoDb ? $row[0]['innodb_len'] : $row[0]['myisam_len']);
                } // if
            } catch (Exception $e) {
                /* fall back to the most conservative settings */
                $isInnoDb = false;
                $minWordLen = 4;
            } // catch

            self::$ftsConfig = [
                max(1, $minWordLen),
                $isInnoDb ? $this->innodb_stop_words : $this->stop_words,
            ];
        } // if

        return self::$ftsConfig;
    }

    // getFtsConfig

    /**
     * Returns the words of a search value which are certain to be in the
     * fulltext index for a matching row: words which are required (prefixed
     * with a +, or part of a +"phrase"), long enough and not a stopword.
     *
     * @return array
     */
    protected function getRequiredIndexWords($searchValue, $minWordLen, $stopWords)
    {
        $words = [];

        if (preg_match_all('/\+("[^"]*"|\S+)/u', $searchValue, $matches)) {
            foreach ($matches[1] as $term) {
                $isPrefix = (substr($term, -1) == '*') && ($term[0] != '"');
                foreach (preg_split('/\s+/u', trim($term, '"*()')) as $word) {
                    if (!preg_match('/^\w+$/u', $word)) {
                        continue;
                    } // if
                    if ((mb_strlen($word) < $minWordLen) || in_array(mb_strtolower($word), $stopWords)) {
                        continue;
                    } // if

                    $words[mb_strtolower($word).($isPrefix ? '*' : '')] = true;
                } // foreach
            } // foreach
        } // if

        return array_keys($words);
    }

    // getRequiredIndexWords

    /**
     * InnoDB phrase searches ("a b") are very slow because every candidate
     * row has to be verified. A required phrase is therefore rewritten into
     * its required words (which the index can resolve quickly), and the exact
     * phrase is checked with a LIKE on that much smaller set of rows.
     *
     * @return array [string rewritten value, array extra LIKE conditions]
     */
    protected function rewriteRequiredPhrases($field, $searchValue, $minWordLen, $stopWords)
    {
        $likeParts = [];

        $rewritten = preg_replace_callback('/\+"([^"]*)"/u', function ($m) use ($field, $minWordLen, $stopWords, &$likeParts) {
            $words = [];
            foreach (preg_split('/\s+/u', trim($m[1])) as $word) {
                if (preg_match('/^\w+$/u', $word) && (mb_strlen($word) >= $minWordLen) && !in_array(mb_strtolower($word), $stopWords)) {
                    $words[] = '+'.$word;
                } // if
            } // foreach

            /* nothing the index can use, keep the phrase as is */
            if (empty($words)) {
                return $m[0];
            } // if

            if (count(preg_split('/\s+/u', trim($m[1]))) > 1) {
                $likeParts[] = ' '.$field.' LIKE '.$this->_db->safe('%'.str_replace(' ', '_', trim($m[1])).'%');
            } // if

            return '+('.implode(' ', $words).')';
        }, $searchValue);

        return [$rewritten, $likeParts];
    }

    // rewriteRequiredPhrases

    /*
     * Constructs a query part to match textfields. Abstracted so we can use
     * a database specific FTS engine if one is provided by the DBMS
     */
    public function createTextQuery($searchFields, $additionalFields)
    {
        SpotTiming::start(__CLASS__.'::'.__FUNCTION__);

        /*
         * Initialize some basic values which are used as return values to
         * make sure always return a valid set
         */
        $filterValueSql = [];
        $sortFields = [];
        $addFields = [];
        $requiredWordSets = [];

        /*
         * MySQL's fultxt search has a minimum length of words for indexes. Per default this is
         * a minimum word length of 4. This means that a searchstring like 'Top 40' will not
         * be found because both 'Top' and '40' are shorter than 4 characters.
         *
         * We query the server setting, and if this is the case, we fall back to a basic LIKE
         * search because it has no such limitation
         */
        list($minWordLen, $stopWords) = $this->getFtsConfig();

        //var_dump($searchFields);

        foreach ($searchFields as $searchItem) {
            $hasTooShortWords = false;
            $hasLongEnoughWords = false;
            $hasStopWords = false;
            $hasNoStopWords = false;
            $hasSearchOpAsTerm = false;
            $hasPhraseWithOnlyInvalids = false;

            $searchMode = 'match-natural';
            $searchValue = trim($searchItem['value']);
            $field = $searchItem['fieldname'];

            /*
             * Look at each individual word. If it is shorter than $minWordLen, we have to perform
             * a LIKE search as well
             *
             * We do not use splitWords() here, because we need to have the lengths of the
             * individual words not of the phrase search.
             *
             * There is one exception, if a phrase search contains ONLY stop words, we cannot
             * use the FTS at all so we should be aware of that.
             */
            $tempSearchValue = str_replace(['+', '-', '"', '(', ')', 'AND', 'NOT', 'OR'], '', $searchValue);
            $termList = explode(' ', $tempSearchValue);
            foreach ($termList as $term) {
                if ((strlen($term) < $minWordLen) && (strlen($term) > 0)) {
                    $hasTooShortWords = true;
                } // if

                if (strlen($term) >= $minWordLen) {
                    $hasLongEnoughWords = true;
                } // if

                /*
                 * If the term is a stopword (things like: the, it, ...) we have to
                 * fallback to a like search as well.
                 */
                if (in_array(strtolower($term), $stopWords) !== false) {
                    $hasStopWords = true;
                } else {
                    $hasNoStopWords = true;
                }// if
            } // foreach

            /*
             * remove any double whitespace because else the MySQL matcher will never
             * find anything
             */
            $searchValue = str_replace('  ', ' ', $searchValue);

            /*
             * MySQL has several types of searches - both boolean and natural matching for
             * FTS is possible.
             *
             * We try some heuristics to select the most appropriate type of search.
             * If a word starts with either an '+' or an '-', we switch to boolean match
             */
            $termList = $this->splitWords($searchValue);
            foreach ($termList as $term) {
                /*
                 * We strip some characters because these are valid, but
                 * can cause the system to not recognize them as operators.
                 *
                 * The string: "(<test)" is such an example -- we only check
                 * the first character so we need to remove the parenthesis.
                 */
                $strippedTerm = trim($term, "()'\"");

                /*
                 * If after stripping the term of these characters, no string
                 * is left, make sure we just abort the matching
                 */
                if (strlen($strippedTerm) < 1) {
                    continue;
                } // if

                /*
                 * + and - are only allowed at the beginning of the search to
                 * enforce it as an search operator. If they are in the
                 * words themselves, we fall back to LIKE
                 */
                if ((strpos($strippedTerm, '-') > 0) || (strpos($strippedTerm, '+') > 0) || (strpos($strippedTerm, '/') > 0)) {
                    $hasSearchOpAsTerm = true;
                } // if

                /*
                 * When there are boolean operators in the string, it's an
                 * boolean search
                 */
                if (strlen($strippedTerm[0]) > 0) {
                    if (strpos('+-~<>', $strippedTerm[0]) !== false) {
                        $searchMode = 'match-boolean';
                        $strippedTerm = trim($strippedTerm, '+-');
                    } // if
                }

                if (strlen(substr($strippedTerm, -1)) > 0) {
                    if (strpos('*', substr($strippedTerm, -1)) !== false) {
                        $searchMode = 'match-boolean';
                        $strippedTerm = trim($strippedTerm, '*');
                    } // if
                }

                if (strlen(substr($term, -1)) > 0) {
                    if (strpos('"', substr($term, -1)) !== false) {
                        $searchMode = 'match-boolean';
                    } // if
                }

                /*
                 * We get the complete phrase here, we need to look into
                 * the phrase terms, because if it only contains invalid terms
                 * (eg: only stopwords, only shortwords, or a combination thereof),
                 * we must disable the FTS completely.
                 *
                 */
                if ((!$hasPhraseWithOnlyInvalids) && ($term[0] == '"')) {
                    $tmpFoundValidTerms = false;

                    $tmpTermList = explode(' ', $strippedTerm);
                    foreach ($tmpTermList as $tmpTerm) {
                        if (strlen($tmpTerm) >= $minWordLen) {
                            if (in_array(strtolower($tmpTerm), $stopWords) === false) {
                                $tmpFoundValidTerms = true;
                            } // if
                        } // if
                    } // foreach

                    if (!$tmpFoundValidTerms) {
                        $hasPhraseWithOnlyInvalids = true;
                    } // if
                } // if
            } // foreach

            // Actually determine the searchmode
            /*
             * Test cases:
             *
             * 		9th Company
             *		Ubuntu 11
             *		Top 40
             *		"Top 40"
             *		South Park
             *		Sex and the city
             *		Rio
             *		"sex and the city 2"
             *		Just Go With It (fallback naar like, enkel stopwoorden of te kort)
             *		"Just Go With It" (fallback naar like, en quotes gestripped)
             *      +"taken 2" +(2012) (fallback naar like, en quotes gestripped - enkel stop woorden maar operators)
             *		+empire +sun
             *		x-art (like search because it contains an -)
             *		50/50 (like search because it contains an /)
             *      Arvo -Lamentate (natural without like)
             *      +"Phantom" +(2013)          <- Shouldn't use a LIKE per se
             *      "The Top"                   <- Should use a LIKE as its only keywords
             *      +"Warehouse 13" +S04        <- Shouldn't use a LIKE per se
             */

            if ($hasPhraseWithOnlyInvalids) {
                $searchMode = 'normal';
            } elseif (($hasTooShortWords || $hasStopWords) && ($hasLongEnoughWords || $hasNoStopWords) && (!$hasSearchOpAsTerm)) {
                if (($hasStopWords && !$hasNoStopWords) || ($hasTooShortWords && !$hasLongEnoughWords)) {
                    $searchMode = 'normal';
                } else {
                    $searchMode = 'both-'.$searchMode;
                } // else
            } elseif ((($hasTooShortWords || $hasStopWords) && (!$hasLongEnoughWords && !$hasNoStopWords)) || $hasSearchOpAsTerm) {
                $searchMode = 'normal';
            } // else

            /*
                        echo 'hasStopWords              : ' . (int) $hasStopWords . '<br>';
                        echo 'hasLongEnoughWords        : ' . (int) $hasLongEnoughWords . '<br>';
                        echo 'hasTooShortWords          : ' . (int) $hasTooShortWords . '<br>';
                        echo 'hasNoStopWords            : ' . (int) $hasNoStopWords . '<br>';
                        echo 'hasSearchOpAsTerm         : ' . (int) $hasSearchOpAsTerm . '<br>';
                        echo 'hasPhraseWithOnlyInvalids : ' . (int) $hasPhraseWithOnlyInvalids . '<br>';
                        echo 'searchmode                : ' . $searchMode . '<br>';
                        die();
            */

            /*
             * Start constructing the query. Sometimes we construct the query
             * both with a LIKE and with a MATCH statement
             */
            $queryPart = [];
            $matchPart = '';
            if (($searchMode == 'normal') || ($searchMode == 'both-match-natural') || ($searchMode == 'both-match-boolean')) {
                $splitted = $this->splitWords($searchValue);
                foreach ($splitted as $splittedTerm) {
                    /*
                     * If the term contains an boolean operator in the beginning,
                     * strip it
                     */
                    $filteredTerm = trim($splittedTerm, '"');
                    $filteredTerm = ltrim($filteredTerm, '+-~<>');
                    $filteredTerm = rtrim($filteredTerm, '*');

                    if (!empty($filteredTerm)) {
                        $filteredTerm = str_replace(' ', '_', $filteredTerm);
                        $filteredTerm = stripslashes($filteredTerm);
                        $filteredTerm = str_replace('"', '', $filteredTerm);
                        $filteredTerm = str_replace('+', '', $filteredTerm);
                        $filteredTerm = str_replace('/', '_', $filteredTerm);
                        $queryPart[] = ' '.$field.' LIKE '.$this->_db->safe('%'.$filteredTerm.'%');
                    } // if
                } // foreach
            } // if

            if (($searchMode == 'match-natural') || ($searchMode == 'both-match-natural')) {
                /* Natural language mode always defaults in MySQL 5.0 en 5.1, but cannot be explicitly defined in MySQL 5.0 */
                $matchPart = ' MATCH('.$field.') AGAINST ('.$this->_db->safe($searchValue).')';
                $queryPart[] = $matchPart;
            } // if

            /*
             * Boolean searches with required or missing terms, will never match if the terms are
             * stopwords because stopwords are not in the index and cannot be found
             */
            if (($searchMode == 'match-boolean') || ($searchMode == 'both-match-boolean')) {
                list($indexValue, $phraseLikes) = $this->rewriteRequiredPhrases($field, $searchValue, $minWordLen, $stopWords);
                if ($searchMode == 'match-boolean') {
                    /* in 'both' mode every term already has a LIKE condition */
                    $queryPart = array_merge($queryPart, $phraseLikes);
                } // if

                $matchPart = ' MATCH('.$field.') AGAINST ('.$this->_db->safe($indexValue).' IN BOOLEAN MODE)';
                $queryPart[] = $matchPart;
            } // if

            if ($searchMode != 'normal') {
                $requiredWordSets[] = $this->getRequiredIndexWords($searchValue, $minWordLen, $stopWords);
            } else {
                $requiredWordSets[] = [];
            } // else

            /*
             * Add the textqueries with an AND per search term
             */
            $filterValueSql[] = ' ('.implode(' AND ', $queryPart).') ';

            /*
             * We add these extended textqueries as a column to the filterlist
             * and use it as a relevance column. This allows us to sort on
             * relevance
             */
            if ($searchMode != 'normal') {
                /*
                 * if we get multiple textsearches, we sort them per order
                 * in the system
                 */
                $tmpSortCounter = count($additionalFields) + count($addFields);

                $addFields[] = $matchPart.' AS searchrelevancy'.$tmpSortCounter;

                $sortFields[] = ['field' => 'searchrelevancy'.$tmpSortCounter,
                    'direction'          => 'DESC',
                    'autoadded'          => true,
                    'friendlyname'       => null, ];
            }  // if
        } // foreach

        /*
         * When several searches on this field are combined with OR, MySQL cannot
         * use the fulltext index for any of them and has to scan every spot.
         * If all searches share required words, we add one MATCH on those
         * words in front of the OR: the index then narrows the rows first.
         */
        $filterPrefix = '';
        if ((count($searchFields) > 1) && (strtoupper($searchFields[0]['booloper']) == 'OR')) {
            $commonWords = array_shift($requiredWordSets);
            foreach ($requiredWordSets as $wordSet) {
                $commonWords = array_values(array_intersect($commonWords, $wordSet));
            } // foreach

            if (!empty($commonWords)) {
                $filterPrefix = ' MATCH('.$searchFields[0]['fieldname'].') AGAINST ('.
                    $this->_db->safe('+'.implode(' +', $commonWords)).' IN BOOLEAN MODE) ';
            } // if
        } // if

        SpotTiming::stop(__CLASS__.'::'.__FUNCTION__, [$filterValueSql, $addFields, $sortFields]);

        //var_dump($filterValueSql);
        //var_dump($addFields);
        //var_dump($sortFields);
        //die();

        return ['filterValueSql' => $filterValueSql,
            'filterPrefix'       => $filterPrefix,
            'additionalTables'   => [],
            'additionalFields'   => $addFields,
            'sortFields'         => $sortFields, ];
    }

    // createTextQuery()
} // dbfts_mysql
