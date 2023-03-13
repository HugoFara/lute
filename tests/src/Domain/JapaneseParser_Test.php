<?php declare(strict_types=1);

require_once __DIR__ . '/../../DatabaseTestBase.php';

use App\Domain\JapaneseParser;
use App\Domain\ParsedToken;
use App\Entity\Text;
use App\Entity\Language;
use App\Entity\Term;

final class JapaneseParser_Test extends DatabaseTestBase
{

    public function childSetUp(): void
    {
        if (!JapaneseParser::MeCab_installed()) {
            $this->markTestSkipped('Skipping test, missing MeCab.');
        }

        $japanese = Language::makeJapanese();
        $this->language_repo->save($japanese, true);
        $this->japanese = $japanese;
    }

    public function tearDown(): void
    {
        // echo "tearing down ... \n";
    }

    public function test_parse_no_words_defined()
    {
        $t = $this->make_text("Test", "私は元気です.", $this->japanese);

        $sql = "select ti2seid, ti2order, ti2text, ti2textlc from textitems2 where ti2woid = 0 order by ti2order";

        $expected = [
            "1; 1; 私; 私",
            "1; 2; は; は",
            "1; 3; 元気; 元気",
            "1; 4; です; です",
            "1; 5; .; .",
            "1; 6; ¶; ¶"
        ];
        DbHelpers::assertTableContains($sql, $expected, 'after parse');
    }

    /**
     * @group parser_tokens
     */
    public function test_getParsedTokens()
    {
        $p = new JapaneseParser();
        $s = "私は元気です。
私は元気です。";
        $actual = $p->getParsedTokens($s, $this->japanese);

        $expected = [
            [ "私", true ],
            [ "は", true ],
            [ "元気", true ],
            [ "です", true ],
            [ "。", false ],
            [ "¶\r", false ],
            [ "私", true ],
            [ "は", true ],
            [ "元気", true ],
            [ "です", true ],
            [ "。", false ],
            [ "¶\r", false ]
        ];
        $expected = array_map(fn($a) => new ParsedToken(...$a), $expected);

        $tostring = function($tokens) {
            $ret = '';
            foreach ($tokens as $tok) {
                $isw = $tok->isWord ? '1' : '0';
                $ret .= "{$tok->token}-{$isw};";
            }
            return $ret;
        };

        $this->assertEquals($tostring($actual), $tostring($expected));
    }

    public function test_parse_words_defined()
    {
        $this->addTerms($this->japanese, [ '私', '元気', 'です' ]);
        $t = $this->make_text("Test", "私は元気です.", $this->japanese);

        $sql = "select ti2woid, ti2seid, ti2order, ti2text from textitems2 where ti2woid > 0 order by ti2order";
        $expected = [
            "1; 1; 1; 私",
            "2; 1; 3; 元気",
            "3; 1; 4; です"
        ];
        DbHelpers::assertTableContains($sql, $expected);
    }

    // futari wasn't getting parsed correctly.
    /**
     * @group futari
     */
    public function test_futari()
    {
        $t = $this->make_text("Test", "二人はどちらの力が強いか.", $this->japanese);

        $sql = "select ti2seid, ti2order, ti2text, ti2wordcount from textitems2
          where ti2order <= 4";

        $expected = [
            "1; 1; 二; 1",
            "1; 2; 人; 1",
            "1; 3; は; 1",
            "1; 4; どちら; 1"
        ];
        DbHelpers::assertTableContains($sql, $expected, 'after parse');
    }

    // Tests to do:

    // carriage returns handled correctly.  e.g:
    // 私は元気です.
    // 彼は元気です.
    // should both be loaded, and there should be a record with carriage returns, eg
    // "x; y; ¶; ¶",

    // terms already defined in "words" table

    // multi-word terms defined.

}
