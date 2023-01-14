<?php declare(strict_types=1);

require_once __DIR__ . '/../../DatabaseTestBase.php';

use App\Domain\JapaneseParser;
use App\Entity\Text;
use App\Entity\Term;

final class JapaneseParser_Test extends DatabaseTestBase
{

    public function childSetUp(): void
    {
        $this->load_languages();
    }

    public function tearDown(): void
    {
        // echo "tearing down ... \n";
    }

    public function test_parse_no_words_defined()
    {
        $t = new Text();
        $t->setTitle("Test");
        $t->setText("私は元気です.");
        $t->setLanguage($this->japanese);
        $this->text_repo->save($t, true);

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


    // Tests to do:

    /*
    public function test_parse_words_defined()
    {
        // TODO - load some JP terms here

        $t = new Text();
        $t->setTitle("Hola.");
        $t->setText("Hola tengo un gato.  No tengo una lista.\nElla tiene una bebida.");
        $t->setLanguage($this->spanish);
        $this->text_repo->save($t, true);

        $t->setTitle("Test");
        $t->setText("私は元気です.");
        $t->setLanguage($this->japanese);
        $this->text_repo->save($t, true);

        $sql = "select ti2woid, ti2seid, ti2order, ti2text from textitems2 where ti2woid > 0 order by ti2order";
        $expected = [
            "1; 1; 5; un gato",
            "2; 2; 16; lista",
            "3; 4; 21; tiene una"
        ];
        DbHelpers::assertTableContains($sql, $expected);
    }

    */
}
