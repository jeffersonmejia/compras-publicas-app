<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\RoleService;

final class RoleController
{
    public function __construct(private readonly RoleService $service) {}
    private function actor(array $session): string { return (string) ($session['subrole'] ?? ''); }
    public function list(array $session, int $page = 1): array { return $this->service->list($this->actor($session), $page); }
    public function create(array $payload, array $session): array { return $this->service->create($payload, $this->actor($session)); }
    public function update(string $code, array $payload, array $session): array { return $this->service->update($code, $payload, $this->actor($session)); }
    public function deactivate(string $code, array $session): array { return $this->service->deactivate($code, $this->actor($session)); }
}
