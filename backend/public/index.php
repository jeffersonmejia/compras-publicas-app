<?php

declare(strict_types=1);

use App\Config\Database;
use App\Config\Env;
use App\Controllers\AuthController;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\NextcloudStorage;

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = __DIR__ . '/../app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
require __DIR__ . '/../config/Env.php';
require __DIR__ . '/../config/Database.php';

header('Content-Type: application/json; charset=utf-8');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (preg_match('#^http://(localhost|127\\.0\\.0\\.1|10\\.|172\\.(1[6-9]|2[0-9]|3[0-1])\\.|192\\.168\\.)[^/]*:4200$#', $origin) === 1) {
    header("Access-Control-Allow-Origin: {$origin}");
}
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

try {
    $env = Env::load(__DIR__ . '/../.env');
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
    $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    if ($basePath !== '' && str_starts_with($path, $basePath)) {
        $path = substr($path, strlen($basePath)) ?: '/';
    }

    if ($path === '/api/health') {
        echo json_encode(['status' => 'ok', 'nextcloudConfigured' => (new NextcloudStorage($env))->isConfigured()]);
        exit;
    }

    if ($path === '/api/auth/login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $payload = json_decode((string) file_get_contents('php://input'), true) ?? [];
        $controller = new AuthController(new AuthService(new UserRepository(Database::connect($env))));
        echo json_encode($controller->login($payload));
        exit;
    }

    http_response_code(404);
    echo json_encode(['message' => 'Ruta no encontrada.']);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['message' => 'Error interno del servidor.']);
}
