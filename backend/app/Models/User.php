<?php

declare(strict_types=1);

namespace App\Models;

final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $username,
        public readonly string $name,
        public readonly ?string $cedula,
        public readonly ?string $lastName,
        public readonly string $passwordHash,
        public readonly string $role,
        public readonly ?string $subrole,
        public readonly bool $isActive,
    ) {
    }
}
