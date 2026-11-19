<?php
declare(strict_types=1);

namespace Moneyapp\Services;

use Moneyapp\Core\Dates;
use PDO;

/**
 * Service de Beneficiários (Payees).
 * Regras:
 *   - name UNIQUE COLLATE NOCASE.
 *   - default_category_id FK com ON DELETE SET NULL.
 *   - Payee é entidade própria (não string solta — princípio 3 da spec).
 */
final class PayeeService
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array{id:int,name:string,default_category_id:?int,default_category_name:?string,notes:?string,created_at:string}> */
    public function list(): array
    {
        $sql = <<<'SQL'
            SELECT p.id, p.name, p.default_category_id, c.name AS default_category_name,
                   p.notes, p.created_at
            FROM payee p
            LEFT JOIN category c ON c.id = p.default_category_id
            ORDER BY p.name COLLATE NOCASE ASC
        SQL;
        $rows = $this->pdo->query($sql)->fetchAll();
        return \array_map(static fn($r) => [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'default_category_id' => $r['default_category_id'] !== null ? (int) $r['default_category_id'] : null,
            'default_category_name' => $r['default_category_name'],
            'notes' => $r['notes'],
            'created_at' => $r['created_at'],
        ], $rows);
    }

    public function create(string $name, ?int $defaultCategoryId = null, ?string $notes = null): int
    {
        $name = \trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Nome é obrigatório.');
        }
        $now = Dates::nowUtcIso();
        $stmt = $this->pdo->prepare(
            'INSERT INTO payee (name, default_category_id, notes, created_at)
             VALUES (?, ?, ?, ?)',
        );
        $stmt->execute([$name, $defaultCategoryId, $notes, $now]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $name, ?int $defaultCategoryId = null, ?string $notes = null): void
    {
        $name = \trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Nome é obrigatório.');
        }
        if ($id <= 0) {
            throw new \InvalidArgumentException('id inválido.');
        }
        $stmt = $this->pdo->prepare(
            'UPDATE payee SET name = ?, default_category_id = ?, notes = ? WHERE id = ?',
        );
        $stmt->execute([$name, $defaultCategoryId, $notes, $id]);
        if ($stmt->rowCount() === 0) {
            // Pode ser "sem alteração" ou id inexistente — verifica existência.
            $check = $this->pdo->prepare('SELECT 1 FROM payee WHERE id = ?');
            $check->execute([$id]);
            if ($check->fetchColumn() === false) {
                throw new \RuntimeException('Beneficiário não encontrado.');
            }
        }
    }

    public function delete(int $id): void
    {
        // ON DELETE SET NULL em transaction.payee_id (o DB cuida).
        $stmt = $this->pdo->prepare('DELETE FROM payee WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Beneficiário não encontrado.');
        }
    }
}
