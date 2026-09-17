<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class RoleService
{
    public function __construct(private readonly PDO $database) {}

    private function manager(string $subrole): void
    {
        if ($subrole !== 'informatica') throw new \DomainException('No autorizado.');
    }

    public function list(string $subrole, int $page = 1): array
    {
        $this->manager($subrole);
        $page = max(1, $page);
        $total = (int) $this->database->query('SELECT COUNT(*) FROM roles')->fetchColumn();
        $pages = max(1, (int) ceil($total / 5));
        $page = min($page, $pages);
        $statement = $this->database->prepare('SELECT code, label, parent_role, is_active FROM roles ORDER BY parent_role IS NOT NULL, parent_role, label LIMIT 5 OFFSET ?');
        $statement->bindValue(1, ($page - 1) * 5, PDO::PARAM_INT); $statement->execute();
        return ['roles' => $statement->fetchAll(PDO::FETCH_ASSOC), 'page' => $page, 'pages' => $pages, 'total' => $total];
    }

    public function create(array $payload, string $subrole): array
    {
        $this->manager($subrole);
        $code = strtolower(trim((string) ($payload['code'] ?? '')));
        $label = trim((string) ($payload['label'] ?? ''));
        $parent = trim((string) ($payload['parent_role'] ?? '')) ?: null;
        if (!preg_match('/^[a-z][a-z0-9_]{2,49}$/', $code) || $label === '') throw new \InvalidArgumentException('Código y nombre de rol inválidos.');
        if ($parent !== null) {
            $check = $this->database->prepare('SELECT is_active FROM roles WHERE code = ?'); $check->execute([$parent]);
            if (!$check->fetchColumn()) throw new \InvalidArgumentException('El rol padre no existe o está inactivo.');
        }
        $statement = $this->database->prepare('INSERT INTO roles (code,label,parent_role,is_active) VALUES (?,?,?,1)');
        $statement->execute([$code, $label, $parent]);
        return ['code' => $code, 'label' => $label, 'parent_role' => $parent, 'is_active' => 1];
    }

    public function update(string $code, array $payload, string $subrole): array
    {
        $this->manager($subrole);
        $label = trim((string) ($payload['label'] ?? ''));
        $parent = trim((string) ($payload['parent_role'] ?? '')) ?: null;
        if ($label === '' || $parent === $code) throw new \InvalidArgumentException('Datos de rol inválidos.');
        $statement = $this->database->prepare('UPDATE roles SET label = ?, parent_role = ? WHERE code = ?');
        $statement->execute([$label, $parent, $code]);
        return ['ok' => $statement->rowCount() >= 0];
    }

    public function deactivate(string $code, string $subrole): array
    {
        $this->manager($subrole);
        $children = $this->database->prepare('SELECT COUNT(*) FROM roles WHERE parent_role = ? AND is_active = 1'); $children->execute([$code]);
        if ((int) $children->fetchColumn() > 0) throw new \DomainException('Desactive primero los subroles dependientes.');
        $statement = $this->database->prepare('UPDATE roles SET is_active = 0 WHERE code = ?'); $statement->execute([$code]);
        return ['ok' => true];
    }
}
