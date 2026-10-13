<?php

declare(strict_types=1);

namespace Quitt\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Quitt\Http\ApiException;
use Quitt\Http\Multipart;
use Quitt\Service\BackupService;
use Quitt\Service\RestoreService;
use Quitt\Support\Clock;
use Slim\Psr7\Stream;

final class BackupController extends Controller
{
    /** GET /backup -> ZIP quitt-sicherung-<datum>.zip */
    public function download(Request $request, Response $response): Response
    {
        $backup = $this->svc(BackupService::class);
        $backup->cleanupTemp();
        $tmp = $backup->tempPath('zip');
        $backup->createArchive($tmp, BackupService::REASON_MANUAL);
        $fh = fopen($tmp, 'rb');
        $size = (int) filesize($tmp);
        @unlink($tmp); // POSIX: Datei lebt bis zum Schliessen; sonst raeumt cleanupTemp() auf
        $date = $this->svc(Clock::class)->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
        return $response
            ->withBody(new Stream($fh))
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Length', (string) $size)
            ->withHeader('Content-Disposition', 'attachment; filename="quitt-sicherung-' . $date . '.zip"');
    }

    /** GET /backups */
    public function list(Request $request, Response $response): Response
    {
        return $this->json($response, $this->svc(BackupService::class)->list());
    }

    /** POST /restore (multipart file) */
    public function restore(Request $request, Response $response): Response
    {
        $restore = $this->svc(RestoreService::class);
        $file = Multipart::file($request, 'file', $restore->maxUploadBytes());
        if ($file === null) {
            throw ApiException::validation(['file' => 'validation.required']);
        }
        return $this->json($response, $restore->restoreUpload($file['bytes']));
    }

    /** POST /backups/{name}/restore */
    public function restoreAutomatic(Request $request, Response $response, array $args): Response
    {
        return $this->json($response, $this->svc(RestoreService::class)->restoreAutomatic((string) $args['name']));
    }
}
