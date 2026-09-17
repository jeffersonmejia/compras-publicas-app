<?php

declare(strict_types=1);

use App\Config\Database;
use App\Config\Env;
use App\Controllers\AuthController;
use App\Controllers\PreRegistrationController;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\JwtService;
use App\Services\NextcloudStorage;
use App\Services\PreRegistrationService;

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
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
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
        $jwt = new JwtService($env['JWT_SECRET'], (int) $env['SESSION_DURATION_MINUTES']);
        $controller = new AuthController(new AuthService(new UserRepository(Database::connect($env)), $jwt));
        echo json_encode($controller->login($payload));
        exit;
    }

    if ($path === '/api/auth/session' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $jwt = new JwtService($env['JWT_SECRET'], (int) $env['SESSION_DURATION_MINUTES']);
        $controller = new AuthController(new AuthService(new UserRepository(Database::connect($env)), $jwt));
        echo json_encode($controller->session($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
        exit;
    }

    if ($path === '/api/registration/verify' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $controller = new PreRegistrationController(new PreRegistrationService(Database::connect($env)));
        echo json_encode($controller->verify((string) ($_GET['cedula'] ?? '')));
        exit;
    }
    if ($path === '/api/registration/complete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $payload = json_decode((string) file_get_contents('php://input'), true) ?? [];
        $controller = new PreRegistrationController(new PreRegistrationService(Database::connect($env)));
        echo json_encode($controller->complete($payload)); exit;
    }

    $jwt = new JwtService($env['JWT_SECRET'], (int) $env['SESSION_DURATION_MINUTES']);
    $token = preg_replace('/^Bearer\s+/i', '', $_SERVER['HTTP_AUTHORIZATION'] ?? '') ?? '';
    $session = $jwt->verify($token);

    if ($path === '/api/documents' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($session === null) { http_response_code(401); echo json_encode(['message' => 'Sesión no válida o expirada.']); exit; }
        $ownRole = $session['subrole'] ?? $session['role'];
        $requestedRole = $session['role'] === 'contratacion_publica' ? (string) ($_GET['role'] ?? $ownRole) : $ownRole;
        $storage = new NextcloudStorage($env);
        $folder = $storage->personalFolder((string) $ownRole, (string) ($session['cedula'] ?? ''), (string) ($session['lastName'] ?? ''));
        $isCategoryView = $session['role'] === 'contratacion_publica' && $requestedRole !== $ownRole;
        echo json_encode(['folder' => $isCategoryView ? $requestedRole : $folder, 'category' => $isCategoryView, 'files' => $isCategoryView ? $storage->listFoldersByRole($requestedRole) : $storage->listFiles($folder)]);
        exit;
    }
    if ($path === '/api/documents/config' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($session === null) { http_response_code(401); echo json_encode(['message' => 'Sesión no válida o expirada.']); exit; }
        echo json_encode(['extensions' => NextcloudStorage::ALLOWED_EXTENSIONS]); exit;
    }
    if ($path === '/api/documents' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($session === null) { http_response_code(401); echo json_encode(['message' => 'Sesión no válida o expirada.']); exit; }
        $role = $session['subrole'] ?? $session['role']; $storage = new NextcloudStorage($env); $folder = $storage->personalFolder((string) $role, (string) ($session['cedula'] ?? ''), (string) ($session['lastName'] ?? '')); $ok = $storage->upload($folder, $_FILES['file'] ?? []);
        if (!$ok) { http_response_code(422); echo json_encode(['ok' => false, 'message' => 'No se pudo cargar el archivo. Verifique su conexión a Nextcloud e inténtelo nuevamente.']); exit; }
        echo json_encode(['ok' => true, 'folder' => $folder, 'message' => 'Archivo cargado correctamente.']); exit;
    }
    if (preg_match('#^/api/documents/(.+)$#', $path, $matches) && $_SERVER['REQUEST_METHOD'] === 'DELETE') {
        if ($session === null) { http_response_code(401); echo json_encode(['message' => 'Sesión no válida o expirada.']); exit; }
        $role = $session['subrole'] ?? $session['role']; $storage = new NextcloudStorage($env); $folder = $storage->personalFolder((string) $role, (string) ($session['cedula'] ?? ''), (string) ($session['lastName'] ?? '')); $ok = $storage->delete($folder, urldecode($matches[1]));
        if (!$ok) http_response_code(422); echo json_encode(['ok' => $ok]); exit;
    }

    if ($path === '/api/pre-registrations' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($session === null) { http_response_code(401); echo json_encode(['message' => 'Sesión no válida o expirada.']); exit; }
        $payload = json_decode((string) file_get_contents('php://input'), true) ?? [];
        $controller = new PreRegistrationController(new PreRegistrationService(Database::connect($env)));
        echo json_encode($controller->create($payload, $session));
        exit;
    }
    if ($path === '/api/pre-registrations' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($session === null) { http_response_code(401); echo json_encode(['message' => 'Sesión no válida o expirada.']); exit; }
        $controller = new PreRegistrationController(new PreRegistrationService(Database::connect($env)));
        echo json_encode($controller->list((int) ($_GET['page'] ?? 1), $session)); exit;
    }
    if (preg_match('#^/api/pre-registrations/(\d+)/toggle$#', $path, $matches) && $_SERVER['REQUEST_METHOD'] === 'PATCH') {
        if ($session === null) { http_response_code(401); echo json_encode(['message' => 'Sesión no válida o expirada.']); exit; }
        $controller = new PreRegistrationController(new PreRegistrationService(Database::connect($env)));
        echo json_encode($controller->toggle((int) $matches[1], $session)); exit;
    }

    http_response_code(404);
    echo json_encode(['message' => 'Ruta no encontrada.']);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['message' => 'Error interno del servidor.']);
}
