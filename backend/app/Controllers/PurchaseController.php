<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Services\PurchaseService;
final class PurchaseController
{
    public function __construct(private readonly PurchaseService $service) {}
    public function users(array $session, string $role, string $query): array { return $this->service->users($session, $role, $query); }
    public function list(array $session): array { return $this->service->list($session); }
    public function create(array $payload, array $session): array { return $this->service->create($payload, $session); }
    public function update(int $id, array $payload, array $session): array { return $this->service->update($id, $payload, $session); }
    public function deactivate(int $id, array $session): array { return $this->service->deactivate($id, $session); }
}
