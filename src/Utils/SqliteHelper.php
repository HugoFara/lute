<?php

namespace App\Utils;

require_once __DIR__ . '/../../db/lib/pdo_migrator.php';

use Symfony\Component\Filesystem\Path;
use App\Utils\Connection;

// Class for namespacing only.
class SqliteHelper {

    public static function DbFilename(): string {
        $filename = $_ENV['DB_FILENAME'];
        // Special token used by symfony to indicate root:
        $symftoken = '%kernel.project_dir%';
        if (str_contains($filename, $symftoken)) {
            $projdir = __DIR__ . '/../..';
            $projdir = Path::canonicalize($projdir);
            $filename = str_replace($symftoken, $projdir, $filename);
        }
        return $filename;
    }

    private static function getMigrator($showlogging = false) {
        $dir = __DIR__ . '/../../db/migrations';
        $repdir = __DIR__ . '/../../db/migrations_repeatable';
        $pdo = Connection::getFromEnvironment();
        $m = new \PdoMigrator($pdo, $dir, $repdir, $showlogging);
        return $m;
    }

    public static function CreateDb() {
        $baseline = __DIR__ . '/../../db/baseline/demo.sqlite';
        $dest = SqliteHelper::DbFilename();
        copy($baseline, $dest);
        SqliteHelper::runMigrations();
    }

    public static function isDemoData(): bool {
        $sql = "select count(*) from books
          where BkID = 1 and BkTitle = 'Tutorial'";
        $conn = Connection::getFromEnvironment();
        $check = $conn
               ->query($sql)
               ->fetch(\PDO::FETCH_NUM);
        $c = $check[0];
        return $c == 1;
    }

    public static function dbIsEmpty() {
        $conn = Connection::getFromEnvironment();
        $check = $conn
               ->query('select count(*) as c from Languages')
               ->fetch(\PDO::FETCH_ASSOC);
        $c = intval($check['c']);
        return $c == 0;
    }

    public static function runMigrations($showlogging = false) {
        $m = SqliteHelper::getMigrator($showlogging);
        $m->process();
    }

    public static function hasPendingMigrations() {
        $m = SqliteHelper::getMigrator(false);
        return count($m->get_pending()) > 0;
    }

    /**
     * Used by public/index.php to initiate and migrate the database.
     *
     * Returns [ messages, error string ]
     */
    public static function doSetup(): array {
        $messages = [];
        $error = null;

        // Verify env vars.
        $config_issues = [];
        $dbf = $_ENV['DB_FILENAME'] ?? '';
        $dbf = trim($dbf);
        if ($dbf == '')
            $config_issues[] = 'Missing key DB_FILENAME';

        $dbu = $_ENV['DATABASE_URL'] ?? '';
        $dbu = trim($dbu);
        if (!str_starts_with($dbu, 'sqlite:'))
            $config_issues[] = 'DATABASE_URL should start with sqlite';

        if (count($config_issues) > 0) {
            $args = [ 'errors' => $config_issues ];
            $error = SqliteHelper::renderError('config_error.html.twig', $args);
            return [ $messages, $error ];
        }

        try {
            $dbexists = file_exists(SqliteHelper::DbFilename());
            if (! $dbexists) {
                SqliteHelper::CreateDb();
                SqliteHelper::runMigrations();
                $messages[] = 'New database created.';
            }
            elseif (SqliteHelper::hasPendingMigrations()) {
                SqliteHelper::runMigrations();
                $messages[] = 'Database updated.';
            }
        }
        catch (\Exception $e) {
            $args = ['errors' => [ $e->getMessage() ]];
            $error = SqliteHelper::renderError('fatal_error.html.twig', $args);
        }

        return [ $messages, $error ];
    }

    private static function renderError($name, $args = []): string {
        // ref https://twig.symfony.com/doc/2.x/api.html
        $loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/../../templates/errors');
        $twig = new \Twig\Environment($loader);
        $template = $twig->load($name);
        return $template->render($args);
    }


    public static function clearDb() {
        // Clean out tables in ref-integrity order.
        $tables = [
            "sentences",
            "texttokens",
            "settings",

            "booktags",
            "bookstats",

            "texttags",
            "wordtags",
            "wordparents",
            "wordimages",

            "tags",
            "tags2",
            "texts",
            "books",
            "words",
            "languages"
        ];
        $conn = Connection::getFromEnvironment();
        foreach ($tables as $t) {
            // truncate doesn't work when referential integrity is set.
            $conn->query("delete from {$t}");
        }
    }

}

?>