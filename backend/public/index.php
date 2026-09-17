<?php

declare(strict_types=1);

use App\Config\Database;
use App\Config\Env;
use App\Controllers\AuthController;
use App\Controllers\PreRegistrationController;
use App\Controllers\RoleController;
use App\Controllers\PurchaseController;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\JwtService;
use App\Services\NextcloudStorage;
use App\Services\PreRegistrationService;
use App\Services\RoleService;
use App\Services\PurchaseService;

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
        $relativePath = (string) ($_GET['path'] ?? '');
        $currentPath = $storage->childPath($folder, $relativePath);
        if ($currentPath === null) { http_response_code(400); echo json_encode(['message' => 'Ruta de carpeta no válida.']); exit; }
        $isCategoryView = $session['role'] === 'contratacion_publica' && $requestedRole !== 'mine' && $relativePath === '';
        $displayFolder = $isCategoryView ? $requestedRole : ($relativePath === '' ? 'documentos' : basename($currentPath));
        echo json_encode(['folder' => $displayFolder, 'path' => $relativePath, 'category' => $isCategoryView, 'files' => $isCategoryView ? $storage->listFoldersByRole($requestedRole) : $storage->listFiles($folder, $relativePath)]);
        exit;
    }
    if ($path === '/api/documents/config' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($session === null) { http_response_code(401); echo json_encode(['message' => 'Sesión no válida o expirada.']); exit; }
        echo json_encode(['extensions' => NextcloudStorage::ALLOWED_EXTENSIONS]); exit;
    }
    if (str_starts_with($path, '/api/roles')) {
        if ($session === null) { http_response_code(401); echo json_encode(['message' => 'Sesión no válida o expirada.']); exit; }
        $controller = new RoleController(new RoleService(Database::connect($env)));
        try {
            if ($path === '/api/roles' && $_SERVER['REQUEST_METHOD'] === 'GET') { echo json_encode($controller->list($session, (int) ($_GET['page'] ?? 1))); exit; }
            if ($path === '/api/roles' && $_SERVER['REQUEST_METHOD'] === 'POST') { echo json_encode($controller->create(json_decode((string) file_get_contents('php://input'), true) ?? [], $session)); exit; }
            if (preg_match('#^/api/roles/([^/]+)$#', $path, $matches) && $_SERVER['REQUEST_METHOD'] === 'PATCH') { echo json_encode($controller->update(urldecode($matches[1]), json_decode((string) file_get_contents('php://input'), true) ?? [], $session)); exit; }
            if (preg_match('#^/api/roles/([^/]+)/deactivate$#', $path, $matches) && $_SERVER['REQUEST_METHOD'] === 'PATCH') { echo json_encode($controller->deactivate(urldecode($matches[1]), $session)); exit; }
        } catch (DomainException $exception) { http_response_code(403); echo json_encode(['message' => $exception->getMessage()]); exit;
        } catch (InvalidArgumentException $exception) { http_response_code(422); echo json_encode(['message' => $exception->getMessage()]); exit; }
        http_response_code(404); echo json_encode(['message' => 'Ruta de roles no encontrada.']); exit;
    }
    if (str_starts_with($path, '/api/purchases') || $path === '/api/purchase-users') {
        if ($session === null) { http_response_code(401); echo json_encode(['message' => 'Sesión no válida o expirada.']); exit; }
        $controller = new PurchaseController(new PurchaseService(Database::connect($env), new NextcloudStorage($env)));
        try {
            if ($path === '/api/purchase-users' && $_SERVER['REQUEST_METHOD'] === 'GET') { echo json_encode($controller->users($session, (string) ($_GET['role'] ?? ''), (string) ($_GET['q'] ?? ''))); exit; }
            if ($path === '/api/purchases' && $_SERVER['REQUEST_METHOD'] === 'GET') { echo json_encode($controller->list($session)); exit; }
            if ($path === '/api/purchases' && $_SERVER['REQUEST_METHOD'] === 'POST') { echo json_encode($controller->create(json_decode((string) file_get_contents('php://input'), true) ?? [], $session)); exit; }
            if (preg_match('#^/api/purchases/(\d+)$#', $path, $matches) && $_SERVER['REQUEST_METHOD'] === 'PATCH') { echo json_encode($controller->update((int) $matches[1], json_decode((string) file_get_contents('php://input'), true) ?? [], $session)); exit; }
            if (preg_match('#^/api/purchases/(\d+)/deactivate$#', $path, $matches) && $_SERVER['REQUEST_METHOD'] === 'PATCH') { echo json_encode($controller->deactivate((int) $matches[1], $session)); exit; }
        } catch (DomainException $exception) { http_response_code(403); echo json_encode(['message' => $exception->getMessage()]); exit;
        } catch (InvalidArgumentException|RuntimeException $exception) { http_response_code(422); echo json_encode(['message' => $exception->getMessage()]); exit; }
        http_response_code(404); echo json_encode(['message' => 'Ruta de compras no encontrada.']); exit;
    }
    if ($path === '/api/documents' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($session === null) { http_response_code(401); echo json_encode(['message' => 'Sesión no válida o expirada.']); exit; }
        $role = $session['subrole'] ?? $session['role']; $storage = new NextcloudStorage($env); $folder = $storage->personalFolder((string) $role, (string) ($session['cedula'] ?? ''), (string) ($session['lastName'] ?? '')); $relativePath = trim(str_replace('\\', '/', (string) ($_POST['path'] ?? '')), '/'); if (!preg_match('#^(procesos|pagos)(/|$)#i', $relativePath) || $storage->childPath($folder, $relativePath) === null) { http_response_code(400); echo json_encode(['message' => 'Solo puede subir archivos dentro de procesos o pagos.']); exit; } $ok = $storage->upload($folder, $_FILES['file'] ?? [], $relativePath);
        if (!$ok) { http_response_code(422); echo json_encode(['ok' => false, 'message' => 'No se pudo cargar el archivo. Verifique su conexión a Nextcloud e inténtelo nuevamente.']); exit; }
        echo json_encode(['ok' => true, 'folder' => $storage->childPath($folder, $relativePath), 'message' => 'Archivo cargado correctamente.']); exit;
    }
    if ($path === '/api/documents/folders' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($session === null) { http_response_code(401); echo json_encode(['message' => 'Sesión no válida o expirada.']); exit; }
        $payload = json_decode((string) file_get_contents('php://input'), true) ?? []; $role = $session['subrole'] ?? $session['role']; $storage = new NextcloudStorage($env); $folder = $storage->personalFolder((string) $role, (string) ($session['cedula'] ?? ''), (string) ($session['lastName'] ?? '')); $relativePath = (string) ($payload['path'] ?? '');
        $ok = $storage->createFolder($folder, trim((string) ($payload['name'] ?? '')), $relativePath); if (!$ok) { http_response_code(422); echo json_encode(['message' => 'No se pudo crear la carpeta. Verifique que el nombre no exista.']); exit; } echo json_encode(['ok' => true]); exit;
    }
    if (preg_match('#^/api/documents/folders/(.+)$#', $path, $matches) && $_SERVER['REQUEST_METHOD'] === 'PATCH') {
        if ($session === null) { http_response_code(401); echo json_encode(['message' => 'Sesión no válida o expirada.']); exit; }
        $payload = json_decode((string) file_get_contents('php://input'), true) ?? []; $role = $session['subrole'] ?? $session['role']; $storage = new NextcloudStorage($env); $folder = $storage->personalFolder((string) $role, (string) ($session['cedula'] ?? ''), (string) ($session['lastName'] ?? '')); $relativePath = (string) ($payload['path'] ?? '');
        $ok = $storage->renameFolder($folder, urldecode($matches[1]), trim((string) ($payload['name'] ?? '')), $relativePath); if (!$ok) { http_response_code(422); echo json_encode(['message' => 'No se pudo renombrar la carpeta.']); exit; } echo json_encode(['ok' => true]); exit;
    }
    if (preg_match('#^/api/documents/folders/(.+)$#', $path, $matches) && $_SERVER['REQUEST_METHOD'] === 'DELETE') {
        if ($session === null) { http_response_code(401); echo json_encode(['message' => 'Sesión no válida o expirada.']); exit; }
        $role = $session['subrole'] ?? $session['role']; $storage = new NextcloudStorage($env); $folder = $storage->personalFolder((string) $role, (string) ($session['cedula'] ?? ''), (string) ($session['lastName'] ?? '')); $relativePath = (string) ($_GET['path'] ?? '');
        $ok = $storage->delete($folder, urldecode($matches[1]), $relativePath); if (!$ok) { http_response_code(422); echo json_encode(['message' => 'No se pudo eliminar la carpeta.']); exit; } echo json_encode(['ok' => true]); exit;
    }
    if (preg_match('#^/api/documents/(.+)$#', $path, $matches) && $_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($session === null) { http_response_code(401); echo json_encode(['message' => 'Sesión no válida o expirada.']); exit; }
        $role = $session['subrole'] ?? $session['role']; $storage = new NextcloudStorage($env); $folder = $storage->personalFolder((string) $role, (string) ($session['cedula'] ?? ''), (string) ($session['lastName'] ?? '')); $name = urldecode($matches[1]); $relativePath = (string) ($_GET['path'] ?? ''); $file = $storage->download($folder, $name, $relativePath);
        if ($file === null) { http_response_code(404); echo json_encode(['message' => 'No se pudo encontrar el archivo solicitado.']); exit; }
        $contentType = strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'pdf' ? 'application/pdf' : $file['contentType']; header('Content-Type: ' . $contentType); $disposition = isset($_GET['inline']) && $_GET['inline'] === '1' && $contentType === 'application/pdf' ? 'inline' : 'attachment'; header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '', basename($name)) . '"'); header('Content-Length: ' . strlen($file['body'])); echo $file['body']; exit;
    }
    if (preg_match('#^/api/documents/(.+)$#', $path, $matches) && $_SERVER['REQUEST_METHOD'] === 'DELETE') {
        if ($session === null) { http_response_code(401); echo json_encode(['message' => 'Sesión no válida o expirada.']); exit; }
        $role = $session['subrole'] ?? $session['role']; $storage = new NextcloudStorage($env); $folder = $storage->personalFolder((string) $role, (string) ($session['cedula'] ?? ''), (string) ($session['lastName'] ?? '')); $ok = $storage->delete($folder, urldecode($matches[1]), (string) ($_GET['path'] ?? ''));
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
