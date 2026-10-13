<?php

declare(strict_types=1);

namespace Quitt\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Quitt\Service\AuthService;
use Quitt\Service\SettingsService;

final class AuthController extends Controller
{
    public function login(Request $request, Response $response): Response
    {
        $body = $this->body($request);
        return $this->json($response, $this->svc(AuthService::class)->login($body['pin'] ?? null));
    }

    public function status(Request $request, Response $response): Response
    {
        return $this->json($response, [
            'authenticated' => $this->auth($request)->via === 'token',
            'read_without_pin' => (bool) $this->svc(SettingsService::class)->get('read_without_pin'),
        ]);
    }

    public function logout(Request $request, Response $response): Response
    {
        $hash = $this->auth($request)->tokenHash;
        if ($hash !== null) {
            $this->svc(AuthService::class)->revokeToken($hash);
        }
        return $this->noContent($response);
    }

    public function changePin(Request $request, Response $response): Response
    {
        $body = $this->body($request);
        $this->svc(AuthService::class)->changePin($body['old_pin'] ?? null, $body['new_pin'] ?? null);
        return $this->noContent($response);
    }

    public function resetInfo(Request $request, Response $response): Response
    {
        return $this->json($response, ['reset_file_present' => $this->svc(AuthService::class)->resetFilePresent()]);
    }

    public function reset(Request $request, Response $response): Response
    {
        $body = $this->body($request);
        return $this->json($response, $this->svc(AuthService::class)->reset($body['recovery_code'] ?? null, $body['new_pin'] ?? null));
    }
}
