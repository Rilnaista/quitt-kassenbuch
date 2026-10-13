<?php

declare(strict_types=1);

namespace Quitt\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Quitt\I18n\Lang;
use Slim\Exception\HttpException;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;

/**
 * Einheitliches Fehlerformat { "error": { "code", "message", ... } } fuer ALLE Fehler.
 * Feldmeldungen (fields) duerfen lang-Schluessel sein und werden hier uebersetzt.
 */
final class ErrorHandler
{
    public function __construct(
        private readonly ResponseFactoryInterface $factory,
        private readonly Lang $lang,
        private readonly LoggerInterface $log,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, \Throwable $e, bool $displayDetails, bool $logErrors, bool $logDetails): ResponseInterface
    {
        $headers = [];
        if ($e instanceof ApiException) {
            $status = $e->status;
            $code = $e->errorCode;
            $message = $this->lang->t($e->messageKey ?? 'error.' . $code, $e->params);
            $extra = $this->translate($e->extra);
            $headers = $e->headers;
            if ($status >= 500 && !in_array($code, ['not_installed', 'not_implemented'], true)) {
                $this->log->error('api error {code}', ['code' => $code, 'path' => $request->getUri()->getPath()]);
            }
        } elseif ($e instanceof HttpNotFoundException) {
            [$status, $code, $extra] = [404, 'not_found', []];
            $message = $this->lang->t('error.not_found');
        } elseif ($e instanceof HttpMethodNotAllowedException) {
            [$status, $code, $extra] = [405, 'method_not_allowed', []];
            $message = $this->lang->t('error.method_not_allowed');
            $headers['Allow'] = implode(', ', $e->getAllowedMethods());
        } elseif ($e instanceof HttpException) {
            [$status, $code, $extra] = [$e->getCode() ?: 400, 'bad_request', []];
            $message = $this->lang->t('error.bad_request');
        } else {
            [$status, $code, $extra] = [500, 'internal_error', []];
            $message = $this->lang->t('error.internal_error');
            $this->log->error('unhandled exception', [
                'exception' => $e,
                'method' => $request->getMethod(),
                'path' => $request->getUri()->getPath(),
            ]);
        }

        $response = Json::respond(
            $this->factory->createResponse(),
            ['error' => array_merge(['code' => $code, 'message' => $message], $extra)],
            $status
        );
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        return $response;
    }

    private function translate(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($v) => $this->translate($v), $value);
        }
        if (is_string($value) && $this->lang->has($value)) {
            return $this->lang->t($value);
        }
        return $value;
    }
}
