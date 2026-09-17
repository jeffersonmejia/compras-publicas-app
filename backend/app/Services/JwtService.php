<?php

declare(strict_types=1);

namespace App\Services;

final class JwtService
{
    public function __construct(private readonly string $secret, private readonly int $durationMinutes)
    {
    }

    /** @param array{id:int, username:string, role:string, subrole:?string} $user */
    public function issue(array $user): array
    {
        $expiresAt = time() + ($this->durationMinutes * 60);
        $payload = ['sub' => $user['id'], 'username' => $user['username'], 'role' => $user['role'], 'subrole' => $user['subrole'], 'exp' => $expiresAt];
        $header = $this->base64Url(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $body = $this->base64Url(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = $this->base64Url(hash_hmac('sha256', "{$header}.{$body}", $this->secret, true));

        return ['token' => "{$header}.{$body}.{$signature}", 'expiresAt' => $expiresAt];
    }

    public function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;
        [$header, $body, $signature] = $parts;
        $expected = $this->base64Url(hash_hmac('sha256', "{$header}.{$body}", $this->secret, true));
        if (!hash_equals($expected, $signature)) return null;
        $payload = json_decode($this->base64UrlDecode($body), true);
        return is_array($payload) && ($payload['exp'] ?? 0) >= time() ? $payload : null;
    }

    private function base64Url(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
    private function base64UrlDecode(string $value): string { return base64_decode(strtr($value, '-_', '+/'), true) ?: ''; }
}
