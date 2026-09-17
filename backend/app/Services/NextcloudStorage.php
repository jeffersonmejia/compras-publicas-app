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

    /** @return list<array{name:string, type:string}> */
    public function listFiles(string $role = ''): array
    {
        if (!$this->isConfigured() || !function_exists('curl_init')) return [];
        $baseUrl = rtrim($this->env['NEXTCLOUD_BASE_URL'], '/');
        $username = rawurlencode($this->env['NEXTCLOUD_USERNAME']);
        $folder = $role === '' ? '' : rawurlencode($role) . '/';
        $url = "{$baseUrl}/remote.php/dav/files/{$username}/{$folder}";
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => 'PROPFIND', CURLOPT_HTTPAUTH => CURLAUTH_BASIC, CURLOPT_USERPWD => "{$this->env['NEXTCLOUD_USERNAME']}:{$this->env['NEXTCLOUD_APP_PASSWORD']}", CURLOPT_HTTPHEADER => ['Depth: 1'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if (!is_string($response) || $status < 200 || $status >= 300) return [];
        $xml = simplexml_load_string($response);
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

    public function upload(string $role, array $file): bool
    {
        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!$this->isConfigured() || !in_array($extension, self::ALLOWED_EXTENSIONS, true) || !isset($file['tmp_name'], $file['name']) || !is_uploaded_file($file['tmp_name'])) return false;
        $url = rtrim($this->env['NEXTCLOUD_BASE_URL'], '/') . '/remote.php/dav/files/' . rawurlencode($this->env['NEXTCLOUD_USERNAME']) . '/' . rawurlencode($role) . '/' . rawurlencode(basename($file['name']));
        $curl = curl_init($url); curl_setopt_array($curl, [CURLOPT_UPLOAD => true, CURLOPT_INFILE => fopen($file['tmp_name'], 'r'), CURLOPT_INFILESIZE => filesize($file['tmp_name']), CURLOPT_HTTPAUTH => CURLAUTH_BASIC, CURLOPT_USERPWD => "{$this->env['NEXTCLOUD_USERNAME']}:{$this->env['NEXTCLOUD_APP_PASSWORD']}", CURLOPT_RETURNTRANSFER => true]); curl_exec($curl); $status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE); curl_close($curl); return $status>=200 && $status<300;
    }

    public function delete(string $role, string $name): bool
    {
        if (!$this->isConfigured()) return false;
        $url = rtrim($this->env['NEXTCLOUD_BASE_URL'], '/') . '/remote.php/dav/files/' . rawurlencode($this->env['NEXTCLOUD_USERNAME']) . '/' . rawurlencode($role) . '/' . rawurlencode(basename($name));
        $curl=curl_init($url); curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>'DELETE',CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,CURLOPT_USERPWD=>"{$this->env['NEXTCLOUD_USERNAME']}:{$this->env['NEXTCLOUD_APP_PASSWORD']}",CURLOPT_RETURNTRANSFER=>true]); curl_exec($curl); $status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE); curl_close($curl); return $status>=200 && $status<300;
    }
}
