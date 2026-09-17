<?php

declare(strict_types=1);

require __DIR__ . '/../config/Env.php';
require __DIR__ . '/../config/Database.php';

use App\Config\Database;
use App\Config\Env;

$env = Env::load(__DIR__ . '/../.env');
$connection = Database::connect($env, false);
$databaseName = str_replace('`', '``', $env['DB_NAME']);
$connection->exec("CREATE DATABASE IF NOT EXISTS `{$databaseName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$connection->exec("USE `{$databaseName}`");
$connection->exec(
    'CREATE TABLE IF NOT EXISTS users (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(120) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        role VARCHAR(50) NOT NULL DEFAULT "administrador",
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$statement = $connection->prepare(
    'INSERT INTO users (username, password_hash, role) VALUES (:username, :password_hash, :role)
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = VALUES(role), is_active = 1'
);
$statement->execute([
    'username' => 'jefferson.mejia',
    'password_hash' => password_hash('12345678', PASSWORD_BCRYPT),
    'role' => 'administrador',
]);

echo "Base de datos y usuario inicial configurados.\n";
