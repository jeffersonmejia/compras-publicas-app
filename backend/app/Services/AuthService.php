<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\UserRepository;

final class AuthService
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    /** @return array{id:int, username:string, role:string}|null */
    public function authenticate(string $username, string $password): ?array
    {
        $user = $this->users->findByUsername(trim($username));
        if ($user === null || !$user->isActive || !password_verify($password, $user->passwordHash)) {
            return null;
        }

        return ['id' => $user->id, 'username' => $user->username, 'role' => $user->role];
    }
}
