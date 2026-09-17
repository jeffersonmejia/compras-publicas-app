<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\User;
use PDO;

final class UserRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    public function findByUsername(string $username): ?User
    {
        $statement = $this->database->prepare(
            'SELECT id, username, name, cedula, last_name, password_hash, role, subrole, is_active FROM users WHERE username = :username LIMIT 1'
        );
        $statement->execute(['username' => $username]);
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return new User((int) $row['id'], $row['username'], $row['name'], $row['cedula'], $row['last_name'], $row['password_hash'], $row['role'], $row['subrole'], (bool) $row['is_active']);
    }
}
