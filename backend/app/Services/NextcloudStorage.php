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
    public function listFiles(string $folder): array
    {
        if (!$this->ensureFolder($folder)) return [];
        $response = $this->request('PROPFIND', $folder . '/', ['Depth: 1']);
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

    public function upload(string $folder, array $file): bool
    {
        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!$this->isConfigured() || !in_array($extension, self::ALLOWED_EXTENSIONS, true) || !isset($file['tmp_name'], $file['name']) || !is_uploaded_file($file['tmp_name']) || !$this->ensureFolder($folder)) return false;
        $handle = fopen($file['tmp_name'], 'rb');
        if ($handle === false) return false;
        $response = $this->request('PUT', $folder . '/' . basename((string) $file['name']), [], $handle, filesize($file['tmp_name']));
        fclose($handle);
        return $response['status'] >= 200 && $response['status'] < 300;
    }

    public function delete(string $folder, string $name): bool
    {
        if (!$this->isConfigured()) return false;
        $response = $this->request('DELETE', $folder . '/' . basename($name));
        return $response['status'] >= 200 && $response['status'] < 300;
    }

    public function createFolder(string $folder, string $name): bool
    {
        if (!$this->ensureFolder($folder) || !$this->validEntryName($name)) return false;
        $response = $this->request('MKCOL', $folder . '/' . trim($name));
        return $response['status'] === 201;
    }

    public function renameFolder(string $folder, string $currentName, string $newName): bool
    {
        if (!$this->validEntryName($currentName) || !$this->validEntryName($newName)) return false;
        $destination = rtrim($this->env['NEXTCLOUD_BASE_URL'], '/') . '/remote.php/dav/files/' . rawurlencode($this->env['NEXTCLOUD_USERNAME']) . '/' . rawurlencode($folder) . '/' . rawurlencode(trim($newName));
        $response = $this->request('MOVE', $folder . '/' . trim($currentName), ['Destination: ' . $destination, 'Overwrite: F']);
        return $response['status'] >= 200 && $response['status'] < 300;
    }

    private function ensureFolder(string $folder): bool
    {
        if (!$this->isConfigured() || !function_exists('curl_init')) return false;
        $response = $this->request('MKCOL', $folder);
        return in_array($response['status'], [201, 301, 405], true);
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
