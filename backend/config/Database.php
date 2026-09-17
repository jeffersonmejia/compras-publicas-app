<?php

declare(strict_types=1);

namespace App\Config;

use PDO;

final class Database
{
    /** @param array<string, string> $env */
    public static function connect(array $env, bool $withDatabase = true): PDO
    {
        $database = $withDatabase ? ';dbname=' . ($env['DB_NAME'] ?? '') : '';
        $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4%s', $env['DB_HOST'], $env['DB_PORT'], $database);

        $password = ($env['DB_PASSWORD'] ?? '') === '' ? null : $env['DB_PASSWORD'];

        return new PDO($dsn, $env['DB_USER'], $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
