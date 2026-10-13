<?php

declare(strict_types=1);

namespace Quitt\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Quitt\Http\ApiException;
use Quitt\Service\DiagnosticsService;
use Quitt\Service\LogReader;
use Quitt\Support\Clock;
use Quitt\Support\Environment;

final class DiagnosticsController extends Controller
{
    /** GET /logs?level=&limit= */
    public function logs(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $fields = [];
        $level = $q['level'] ?? null;
        if ($level !== null && (!is_string($level) || !in_array($level, LogReader::LEVELS, true))) {
            $fields['level'] = 'validation.invalid_choice';
        }
        $limit = $q['limit'] ?? LogReader::DEFAULT_LIMIT;
        if (!is_string($limit) && !is_int($limit) || !preg_match('/^\d{1,4}$/', (string) $limit) || (int) $limit < 1) {
            $fields['limit'] = 'validation.invalid_choice';
        }
        if ($fields !== []) {
            throw ApiException::validation($fields);
        }
        return $this->json($response, $this->svc(LogReader::class)->read($level, (int) $limit));
    }

    /** GET /diagnostics -> quitt-diagnose-<datum>.json */
    public function diagnostics(Request $request, Response $response): Response
    {
        $json = $this->svc(DiagnosticsService::class)->buildJson(
            Environment::isHttps($request),
            Environment::isDocker(),
            (string) $this->c->get('base_path')
        );
        $date = $this->svc(Clock::class)->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
        $response->getBody()->write($json);
        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="quitt-diagnose-' . $date . '.json"');
    }
}
