<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;

final class AuthController
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function login(array $payload): array
    {
        $username = (string) ($payload['username'] ?? '');
        $password = (string) ($payload['password'] ?? '');
        $user = $this->auth->authenticate($username, $password);

        if ($user === null) {
            http_response_code(401);
            return ['message' => 'Nombre de usuario o contraseña incorrecta.'];
        }

        return ['user' => $user];
    }
}
