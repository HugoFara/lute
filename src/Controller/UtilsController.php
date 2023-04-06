<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;

use App\Utils\MysqlBackup;
use App\Utils\MysqlExportCSV;
use App\Repository\SettingsRepository;
use App\Utils\ImportCSV;

#[Route('/utils')]
class UtilsController extends AbstractController
{

    #[Route('/import_csv', name: 'app_import_csv', methods: ['GET'])]
    public function import_csv(): Response
    {
        ImportCSV::doImport();
        return $this->redirectToRoute(
            'app_index',
            [ ],
            Response::HTTP_SEE_OTHER
        );
    }

    #[Route('/backup', name: 'app_backup_index', methods: ['GET'])]
    public function backup(): Response
    {
        return $this->render('utils/backup.html.twig', [
            'backup_folder' => $_ENV['BACKUP_DIR']
        ]);
    }

    #[Route('/do_backup', name: 'app_do_backup_index', methods: ['POST'])]
    public function do_backup(SettingsRepository $repo): JsonResponse
    {
        try {
            $b = new MysqlBackup($_ENV, $repo);
            $f = $b->create_backup();
            return $this->json($f);
        }
        catch(\Exception $e) {
            return new JsonResponse(array('errmsg' => $e->getMessage()), 500);
        }
    }

    #[Route('/export_csv', name: 'app_export_csv', methods: ['GET'])]
    public function export_csv(): Response
    {
        MysqlExportCSV::doExport();
        return $this->render('utils/csv_export.html.twig');
    }

}
