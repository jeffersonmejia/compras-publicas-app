<?php

declare(strict_types=1);

namespace App\Services;

final class NextcloudStorage
{
    public const ALLOWED_EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx'];

    /** @param array<string, string> $env */
    public function __construct(private readonly array $env) {}

    public function isConfigured(): bool
    {
        return ($this->env['NEXTCLOUD_BASE_URL'] ?? '') !== '' && ($this->env['NEXTCLOUD_USERNAME'] ?? '') !== '' && ($this->env['NEXTCLOUD_APP_PASSWORD'] ?? '') !== '';
    }

    public function personalFolder(string $role, string $cedula, string $lastName): string
    {
        $safeRole = preg_replace('/[^a-z0-9_]/', '', strtolower($role)) ?: 'usuario';
        $safeCedula = preg_replace('/\D/', '', $cedula) ?: 'sin_cedula';
        $normalizedName = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $lastName) ?: $lastName;
        $safeLastName = preg_replace('/[^a-z0-9]/', '', strtolower($normalizedName)) ?: 'sin_apellido';
        return "{$safeRole}_{$safeCedula}_{$safeLastName}";
    }

    /** @return list<array{name:string, type:string}> */
    public function listFiles(string $folder, string $relativePath = ''): array
    {
        $path = $this->childPath($folder, $relativePath);
        if ($path === null || !$this->ensureFolder($path)) return [];
        $response = $this->request('PROPFIND', $path . '/', ['Depth: 1']);
        if ($response['status'] < 200 || $response['status'] >= 300 || !is_string($response['body'])) return [];
        $xml = simplexml_load_string($response['body']);
        if ($xml === false) return [];
        $xml->registerXPathNamespace('d', 'DAV:');
        $nodes = $xml->xpath('//d:response') ?: [];
        $files = [];
        foreach (array_slice($nodes, 1) as $node) {
            $href = (string) ($node->xpath('./d:href')[0] ?? '');
            $name = rawurldecode(basename(rtrim($href, '/')));
            if ($name !== '') $files[] = ['name' => $name, 'type' => str_ends_with($href, '/') ? 'folder' : 'file'];
        }
        return $files;
    }

    /** @return list<array{name:string, type:string}> */
    public function listFoldersByRole(string $role): array
    {
        if (!$this->isConfigured()) return [];
        $response = $this->request('PROPFIND', '', ['Depth: 1']);
        if ($response['status'] < 200 || $response['status'] >= 300 || !is_string($response['body'])) return [];
        $xml = simplexml_load_string($response['body']);
        if ($xml === false) return [];
        $xml->registerXPathNamespace('d', 'DAV:');
        $nodes = $xml->xpath('//d:response') ?: [];
        $prefix = strtolower($role) . '_';
        $folders = [];
        foreach (array_slice($nodes, 1) as $node) {
            $href = (string) ($node->xpath('./d:href')[0] ?? '');
            $name = rawurldecode(basename(rtrim($href, '/')));
            if (str_ends_with($href, '/') && str_starts_with(strtolower($name), $prefix)) $folders[] = ['name' => $name, 'type' => 'folder'];
        }
        return $folders;
    }

    public function upload(string $folder, array $file, string $relativePath = ''): bool
    {
        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $path = $this->childPath($folder, $relativePath);
        if ($path === null || !$this->isConfigured() || !in_array($extension, self::ALLOWED_EXTENSIONS, true) || !isset($file['tmp_name'], $file['name']) || !is_uploaded_file($file['tmp_name']) || !$this->ensureFolder($path)) return false;
        $handle = fopen($file['tmp_name'], 'rb');
        if ($handle === false) return false;
        $response = $this->request('PUT', $path . '/' . basename((string) $file['name']), [], $handle, filesize($file['tmp_name']));
        fclose($handle);
        return $response['status'] >= 200 && $response['status'] < 300;
    }

    public function delete(string $folder, string $name, string $relativePath = ''): bool
    {
        $path = $this->childPath($folder, $relativePath);
        if ($path === null || !$this->isConfigured() || !$this->validEntryName($name)) return false;
        $response = $this->request('DELETE', $path . '/' . basename($name));
        return $response['status'] >= 200 && $response['status'] < 300;
    }

    /** @return array{body:string, contentType:string}|null */
    public function download(string $folder, string $name, string $relativePath = ''): ?array
    {
        $path = $this->childPath($folder, $relativePath);
        if ($path === null || !$this->isConfigured() || !$this->validEntryName($name)) return null;
        $response = $this->request('GET', $path . '/' . trim($name));
        if ($response['status'] < 200 || $response['status'] >= 300 || !is_string($response['body'])) return null;
        return ['body' => $response['body'], 'contentType' => 'application/octet-stream'];
    }

    public function createFolder(string $folder, string $name, string $relativePath = ''): bool
    {
        $path = $this->childPath($folder, $relativePath);
        if ($path === null || !$this->ensureFolder($path) || !$this->validEntryName($name)) return false;
        $response = $this->request('MKCOL', $path . '/' . trim($name));
        return $response['status'] === 201;
    }

    public function renameFolder(string $folder, string $currentName, string $newName, string $relativePath = ''): bool
    {
        $path = $this->childPath($folder, $relativePath);
        if ($path === null || !$this->validEntryName($currentName) || !$this->validEntryName($newName)) return false;
        $destination = rtrim($this->env['NEXTCLOUD_BASE_URL'], '/') . '/remote.php/dav/files/' . rawurlencode($this->env['NEXTCLOUD_USERNAME']) . '/' . implode('/', array_map('rawurlencode', explode('/', $path))) . '/' . rawurlencode(trim($newName));
        $response = $this->request('MOVE', $path . '/' . trim($currentName), ['Destination: ' . $destination, 'Overwrite: F']);
        return $response['status'] >= 200 && $response['status'] < 300;
    }

    private function ensureFolder(string $folder): bool
    {
        if (!$this->isConfigured() || !function_exists('curl_init')) return false;
        $response = $this->request('MKCOL', $folder);
        return in_array($response['status'], [201, 301, 405], true);
    }

    public function childPath(string $root, string $relativePath): ?string
    {
        $root = trim($root, '/');
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
        if ($root === '' || $relativePath === '') return $root !== '' ? $root : null;
        $segments = explode('/', $relativePath);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || $segment !== basename($segment)) return null;
        }
        return $root . '/' . implode('/', $segments);
    }

    private function validEntryName(string $name): bool
    {
        return $name !== '' && $name === basename($name) && !str_contains($name, '..');
    }

    /** @param list<string> $headers @return array{status:int, body:string|false} */
    private function request(string $method, string $path, array $headers = [], mixed $input = null, ?int $size = null): array
    {
        $base = rtrim($this->env['NEXTCLOUD_BASE_URL'], '/') . '/remote.php/dav/files/' . rawurlencode($this->env['NEXTCLOUD_USERNAME']) . '/';
        $segments = array_map('rawurlencode', explode('/', trim($path, '/')));
        $url = $base . implode('/', $segments) . (str_ends_with($path, '/') ? '/' : '');
        $curl = curl_init($url);
        $options = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPAUTH => CURLAUTH_BASIC, CURLOPT_USERPWD => "{$this->env['NEXTCLOUD_USERNAME']}:{$this->env['NEXTCLOUD_APP_PASSWORD']}", CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30];
        if ($input !== null) $options[CURLOPT_INFILE] = $input;
        if ($size !== null) { $options[CURLOPT_UPLOAD] = true; $options[CURLOPT_INFILESIZE] = $size; }
        curl_setopt_array($curl, $options);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        return ['status' => $status, 'body' => $body];
    }
}
