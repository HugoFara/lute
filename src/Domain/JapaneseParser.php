<?php

namespace App\Domain;

use App\Entity\Text;
use App\Entity\Language;
use App\Repository\TextItemRepository;
use App\Domain\TextStatsCache;
use App\Utils\Connection;

class JapaneseParser {

    /** PUBLIC **/
    
    private $conn;

    public function __construct()
    {
        $this->conn = Connection::getFromEnvironment();
    }

    public function parse(Text $text) {
        $this->parseText($text);
    }

    /** PRIVATE **/

    private function exec_sql($sql, $params = null) {
        // echo $sql . "\n";
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            throw new \Exception($this->conn->error);
        }
        if ($params) {
            $stmt->bind_param(...$params);
        }
        if (!$stmt->execute()) {
            throw new \Exception($stmt->error);
        }
        return $stmt->get_result();
    }
 
    private function parseText(Text $text) {

        $id = $text->getID();
        $cleanup = [
            "DROP TABLE IF EXISTS temptextitems",
            "DELETE FROM sentences WHERE SeTxID = $id",
            "DELETE FROM textitems2 WHERE Ti2TxID = $id"
        ];
        foreach ($cleanup as $sql)
            $this->exec_sql($sql);

        $cleantext = $this->mecab_clean_text($text);

        $arr = $this->build_insert_array($cleantext);
        $this->load_temptextitems_from_array($arr);
        $this->import_temptextitems($text);

        TextItemRepository::mapForText($text);

        TextStatsCache::force_refresh($text);

        // $this->exec_sql("DROP TABLE IF EXISTS temptextitems");
    }


    /**
     * Returns path to the MeCab application.
     * MeCab can split Japanese text word by word
     *
     * @param string $mecab_args Arguments to add
     *
     * @return string OS-compatible command
     */
    function get_mecab_path($mecab_args = ''): string 
    {
        $os = strtoupper(substr(PHP_OS, 0, 3));
        $mecab_args = escapeshellcmd($mecab_args);
        if ($os == 'LIN' || $os == 'DAR') {
            if (shell_exec("command -v mecab")) {
                return 'mecab' . $mecab_args; 
            }
            throw new \Exception("MeCab not installed or not on your PATH");
        }
        if ($os == 'WIN') {
            if (shell_exec('where /R "%ProgramFiles%\\MeCab\\bin" mecab.exe')) { 
                return '"%ProgramFiles%\\MeCab\\bin\\mecab.exe"' . $mecab_args;
            } 
            if (shell_exec('where /R "%ProgramFiles(x86)%\\MeCab\\bin" mecab.exe')) {
                return '"%ProgramFiles(x86)%\\MeCab\\bin\\mecab.exe"' . $mecab_args; 
            }
            if (shell_exec('where mecab.exe')) {
                return 'mecab.exe' . $mecab_args; 
            }
            throw new \Exception("MeCab not installed or not on your PATH");
        }
        throw new \Exception("Unknown OS {$os}");
    }

    /**
     * Sanitize a Japanese text for insertion in the database.
     * 
     * Separate lines \n, end sentences with \r and gives pairs (charcount\tstring)
     * 
     * @param string $text Text to clean, using regexs.
     */
    private function mecab_clean_text(Text $entity): string
    {
        $text = $entity->getText();

        $text = trim(preg_replace('/[ \t]+/u', ' ', $text));

        $file_name = tempnam(sys_get_temp_dir(), "lute");
        // We use the format "word  num num" for all nodes
        $mecab_args = " -F %m\\t%t\\t%h\\n -U %m\\t%t\\t%h\\n -E EOP\\t3\\t7\\n";
        $mecab_args .= " -o $file_name ";
        $mecab = $this->get_mecab_path($mecab_args);

        // WARNING: \n is converted to PHP_EOL here!
        $handle = popen($mecab, 'w');
        fwrite($handle, $text);
        pclose($handle);

        $handle = fopen($file_name, 'r');
        $mecabed = fread($handle, filesize($file_name));
        fclose($handle);

        $outtext = "";
        foreach (explode(PHP_EOL, $mecabed) as $line) {
            // Skip blank lines, or the following line's array
            // assignment fails.
            if (trim($line) == "")
                continue;

            $tab = mb_chr(9);
            list($term, $node_type, $third) = explode($tab, $line);

            $isParagraph = ($term == 'EOP' && $third == '7');
            if ($isParagraph)
                $term = "¶\r";

            $count = 0;
            if (str_contains('267', $node_type))
                $count = 1;

            $outtext .= ((string) $count) . "\t$term\n";
        }
        unlink($file_name);
        return $outtext;
    }


    // Instance state required while loading temp table:
    private int $sentence_number = 0;
    private int $ord = 0;

    /**
     * Convert each non-empty line of text into an array
     * [ sentence_number, order, wordcount, word ].
     */
    private function build_insert_array($text): array {
        $lines = explode("\n", $text);
        $lines = array_filter($lines, fn($s) => $s != '');

        // Make the array row, incrementing $sentence_number as
        // needed.
        $makeentry = function($line) {
            [ $wordcount, $s ] = explode("\t", $line);
            $this->ord += 1;
            $ret = [ $this->sentence_number, $this->ord, intval($wordcount), rtrim($s, "\r") ];

            // Word ending with \r marks the end of the current
            // sentence.
            if (str_ends_with($s, "\r")) {
                $this->sentence_number += 1;
            }
            return $ret;
        };

        $arr = array_map($makeentry, $lines);

        // var_dump($arr);
        return $arr;
    }


    // Load array
    private function load_temptextitems_from_array(array $arr) {
        $this->conn->query("drop table if exists temptextitems");

        // Note the charset/collation here is very important!
        // If not used, then when the import is done, a new text item
        // can match to both an accented *and* unaccented word.
        $sql = "CREATE TABLE temptextitems (
          TiSeID mediumint(8) unsigned NOT NULL,
          TiOrder smallint(5) unsigned NOT NULL,
          TiWordCount tinyint(3) unsigned NOT NULL,
          TiText varchar(250) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL
        ) ENGINE=MEMORY DEFAULT CHARSET=utf8";
        $this->conn->query($sql);

        $chunks = array_chunk($arr, 1000);

        foreach ($chunks as $chunk) {
            $this->insert_array_chunk($chunk);
        }
    }

    // Insert each record in chunk in a prepared statement,
    // where chunk record is [ sentence_num, ord, wordcount, word ].
    private function insert_array_chunk(array $chunk) {
        $sqlbase = "insert into temptextitems values ";
        $n = count($chunk);
        $valplaceholders = str_repeat("(?,?,?,?),", $n);
        $valplaceholders = rtrim($valplaceholders, ',');
        $parmtypes = str_repeat("iiis", $n);

        // Flatten the records in the chunks.
        // Ref belyas's solution in https://gist.github.com/SeanCannon/6585889.
        $prmarray = [];
        array_map(
            function($arr) use (&$prmarray) {
                $prmarray = array_merge($prmarray, $arr);
            },
            $chunk
        );

        $sql = $sqlbase . $valplaceholders;
        // echo $sql . "\n";
        // echo $parmtypes . "\n";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($parmtypes, ...$prmarray);
        if (!$stmt->execute()) {
            throw new \Exception($stmt->error);
        }
    }


    /**
     * Move data from temptextitems to final tables.
     * 
     * @param int    $id  New default text ID
     * @param int    $lid New default language ID
     * 
     * @return void
     */
    private function import_temptextitems(Text $text)
    {
        $id = $text->getID();
        $lid = $text->getLanguage()->getLgID();

        $sql = "INSERT INTO sentences (SeLgID, SeTxID, SeOrder, SeFirstPos, SeText)
            SELECT {$lid}, {$id}, TiSeID, 
            min(if(TiWordCount=0, TiOrder+1, TiOrder)),
            GROUP_CONCAT(TiText order by TiOrder SEPARATOR \"\") 
            FROM temptextitems 
            group by TiSeID";
        $this->exec_sql($sql);

        $minmax = "SELECT MIN(SeID) as minseid, MAX(SeID) as maxseid FROM sentences WHERE SeTxID = {$id}";
        $rec = $this->conn
             ->query($minmax)->fetch_array();
        $firstSeID = intval($rec['minseid']);
        $lastSeID = intval($rec['maxseid']);
    
        $addti2 = "INSERT INTO textitems2 (
                Ti2LgID, Ti2TxID, Ti2WoID, Ti2SeID, Ti2Order, Ti2WordCount, Ti2Text, Ti2TextLC
            )
            select {$lid}, {$id}, WoID, TiSeID + {$firstSeID}, TiOrder, TiWordCount, TiText, lower(TiText) 
            FROM temptextitems 
            left join words 
            on lower(TiText) = WoTextLC and TiWordCount>0 and WoLgID = {$lid} 
            order by TiOrder,TiWordCount";

        $this->exec_sql($addti2);
    }

}