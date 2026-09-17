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
    'CREATE TABLE IF NOT EXISTS roles (
        code VARCHAR(50) PRIMARY KEY,
        label VARCHAR(100) NOT NULL,
        parent_role VARCHAR(50) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);
$roles = [
    ['contratacion_publica', 'Contratación Pública', null],
    ['bienes_activos_fijos', 'Bienes y Activos Fijos', null],
    ['contador', 'Contador', null],
    ['director', 'Director', null],
    ['operador', 'Operador', null],
    ['informatica', 'Informática', 'operador'],
    ['talento_humano', 'Talento Humano', 'operador'],
    ['comunicacion', 'Comunicación', 'operador'],
];
$roleStatement = $connection->prepare('INSERT INTO roles (code, label, parent_role) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE label = VALUES(label), parent_role = VALUES(parent_role)');
foreach ($roles as $role) $roleStatement->execute($role);

$connection->exec(
    'CREATE TABLE IF NOT EXISTS users (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(120) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        role VARCHAR(50) NOT NULL DEFAULT "contratacion_publica",
        subrole VARCHAR(50) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);
$columns = $connection->query("SHOW COLUMNS FROM users LIKE 'subrole'")->fetchAll();
if ($columns === []) $connection->exec('ALTER TABLE users ADD COLUMN subrole VARCHAR(50) NULL AFTER role');

$statement = $connection->prepare(
    'INSERT INTO users (username, password_hash, role) VALUES (:username, :password_hash, :role)
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = VALUES(role), is_active = 1'
);
$statement->execute([
    'username' => 'jefferson.mejia',
    'password_hash' => password_hash('12345678', PASSWORD_BCRYPT),
    'role' => 'contratacion_publica',
]);

echo "Base de datos y usuario inicial configurados.\n";
