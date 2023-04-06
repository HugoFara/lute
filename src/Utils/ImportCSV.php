<?php

namespace App\Utils;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

class ImportCSV {


    // ref https://gist.github.com/fcingolani/5364532
    private static function import_csv_to_sqlite(&$pdo, $csv_path, $table)
    {
        if (($csv_handle = fopen($csv_path, "r")) === FALSE)
            throw new \Exception('Cannot open CSV file');
		
        $delimiter = ',';

        $fields = fgetcsv($csv_handle, 0, $delimiter);

        $pdo->beginTransaction();
	
        $insert_fields_str = join(', ', $fields);
        $insert_values_str = join(', ', array_fill(0, count($fields),  '?'));
        $insert_sql = "INSERT INTO $table ($insert_fields_str) VALUES ($insert_values_str)";
        $insert_sth = $pdo->prepare($insert_sql);
	
        $inserted_rows = 0;
        while (($data = fgetcsv($csv_handle, 0, $delimiter)) !== FALSE) {
            $insert_sth->execute($data);
            $inserted_rows++;

            if (intval($inserted_rows / 1000) * 1000 == $inserted_rows) {
                $pdo->commit();
                $pdo->beginTransaction();
            }
        }
	
        $pdo->commit();
	
        fclose($csv_handle);

        /*
          return array(
          'table' => $table,
          'fields' => $fields,
          'insert' => $insert_sth,
          'inserted_rows' => $inserted_rows
          );
        */
    }
    
    public static function doImport() {
        $conn = Connection::getFromEnvironment();
        $sourcedir = __DIR__ . '/../../csv_export';
        $sourcedir = Path::canonicalize($sourcedir);
        $tables = [
            "books",
            "bookstats",
            "booktags",
            "languages",
            "sentences",
            "settings",
            "tags",
            "tags2",
            "texts",
            "texttags",
            "texttokens",
            "wordimages",
            "wordparents",
            "words",
            "wordtags"
        ];
        foreach ($tables as $t) {
            $csv_path = "{$sourcedir}/{$t}.csv";
            ImportCSV::import_csv_to_sqlite($conn, $csv_path, $t);
        }
    }

}
