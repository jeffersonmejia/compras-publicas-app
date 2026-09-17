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
        parent_role VARCHAR(50) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);
$roleColumns = $connection->query('SHOW COLUMNS FROM roles')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('is_active', $roleColumns, true)) $connection->exec('ALTER TABLE roles ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1');
$roles = [
    ['contratacion_publica', 'Contratación Pública', null],
    ['bienes_activos_fijos', 'Bienes y Activos Fijos', null],
    ['contador', 'Contador', null],
    ['director', 'Director', null],
    ['administrativo', 'Administrativo', 'director'],
    ['financiero', 'Financiero', 'director'],
    ['medico', 'Médico', 'director'],
    ['planificacion', 'Planificación', 'director'],
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
        name VARCHAR(150) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        role VARCHAR(50) NOT NULL DEFAULT "contratacion_publica",
        subrole VARCHAR(50) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);
$columns = $connection->query("SHOW COLUMNS FROM users LIKE 'subrole'")->fetchAll();
if ($columns === []) $connection->exec('ALTER TABLE users ADD COLUMN subrole VARCHAR(50) NULL AFTER role');
$nameColumn = $connection->query("SHOW COLUMNS FROM users LIKE 'name'")->fetchAll();
if ($nameColumn === []) $connection->exec("ALTER TABLE users ADD COLUMN name VARCHAR(150) NOT NULL DEFAULT '' AFTER username");
$userColumns = $connection->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('cedula', $userColumns, true)) $connection->exec('ALTER TABLE users ADD COLUMN cedula VARCHAR(10) NULL UNIQUE AFTER name');
if (!in_array('last_name', $userColumns, true)) $connection->exec('ALTER TABLE users ADD COLUMN last_name VARCHAR(100) NULL AFTER cedula');

$statement = $connection->prepare(
    'INSERT INTO users (username, name, cedula, last_name, password_hash, role, subrole) VALUES (:username, :name, :cedula, :last_name, :password_hash, :role, :subrole)
     ON DUPLICATE KEY UPDATE name = VALUES(name), cedula = VALUES(cedula), last_name = VALUES(last_name), password_hash = VALUES(password_hash), role = VALUES(role), subrole = VALUES(subrole), is_active = 1'
);
$statement->execute([
    'username' => 'jefferson.mejia',
    'name' => 'Jefferson Mejia',
    'cedula' => '1317268876',
    'last_name' => 'Mejia',
    'password_hash' => password_hash('12345678', PASSWORD_BCRYPT),
    'role' => 'operador',
    'subrole' => 'informatica',
]);
$connection->exec(
    'CREATE TABLE IF NOT EXISTS pre_registrations (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        cedula VARCHAR(10) NOT NULL UNIQUE,
        status VARCHAR(20) NOT NULL DEFAULT "pendiente",
        first_names VARCHAR(150) NULL,
        last_names VARCHAR(150) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT UNSIGNED NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_pre_registration_creator FOREIGN KEY (created_by) REFERENCES users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);
$preColumns = $connection->query('SHOW COLUMNS FROM pre_registrations')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('first_names', $preColumns, true)) $connection->exec('ALTER TABLE pre_registrations ADD COLUMN first_names VARCHAR(150) NULL AFTER status');
if (!in_array('last_names', $preColumns, true)) $connection->exec('ALTER TABLE pre_registrations ADD COLUMN last_names VARCHAR(150) NULL AFTER first_names');
if (!in_array('is_active', $preColumns, true)) $connection->exec('ALTER TABLE pre_registrations ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER last_names');
if (!in_array('role_code', $preColumns, true)) $connection->exec('ALTER TABLE pre_registrations ADD COLUMN role_code VARCHAR(50) NOT NULL DEFAULT "operador" AFTER status');
$connection->exec("UPDATE pre_registrations SET status='registrado', first_names='Jefferson', last_names='Mejia', role_code='contratacion_publica' WHERE cedula='1317268876' AND created_by=(SELECT id FROM users WHERE username='jefferson.mejia')");
$connection->exec('CREATE TABLE IF NOT EXISTS public_purchases (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(180) NOT NULL,
    slug VARCHAR(180) NOT NULL,
    delegated_user_id INT UNSIGNED NOT NULL,
    delegated_by INT UNSIGNED NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_purchase_user FOREIGN KEY (delegated_user_id) REFERENCES users(id),
    CONSTRAINT fk_purchase_delegate FOREIGN KEY (delegated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

echo "Base de datos y usuario inicial configurados.\n";
