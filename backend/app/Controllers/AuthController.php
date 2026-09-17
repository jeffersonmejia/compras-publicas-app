<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;

final class AuthController
{
    public function __construct(private readonly AuthService $auth) {}

    public function login(array $payload): array
    {
        $session = $this->auth->authenticate((string) ($payload['username'] ?? ''), (string) ($payload['password'] ?? ''));
        if ($session === null) {
            http_response_code(401);
            return ['message' => 'Nombre de usuario o contraseña incorrecta.'];
        }
        return $session;
    }

    public function session(string $authorization): array
    {
        $token = preg_replace('/^Bearer\s+/i', '', $authorization) ?? '';
        $session = $this->auth->validateSession($token);
        if ($session === null) {
            http_response_code(401);
            return ['message' => 'Sesión no válida o expirada.'];
        }
        return ['session' => $session];
    }
}
