<?php
declare(strict_types=1);

namespace Moneyapp\Services;

use Moneyapp\Core\Dates;
use Moneyapp\Core\Money;
use Moneyapp\Core\MoneyException;
use Moneyapp\Core\DateException;
use PDO;

/**
 * Service de Instituições.
 * Regras:
 *   - name UNIQUE COLLATE NOCASE.
 *   - ON DELETE SET NULL em account.institution_id (o DB cuida).
 */
final class InstitutionService
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array{id:int,name:string,notes:?string,created_at:string,account_count:int}> */
    public function list(): array
    {
        $sql = <<<'SQL'
            SELECT i.id, i.name, i.notes, i.created_at,
                   (SELECT COUNT(*) FROM account a WHERE a.institution_id = i.id) AS account_count
            FROM institution i
            ORDER BY i.name COLLATE NOCASE ASC
        SQL;
        $rows = $this->pdo->query($sql)->fetchAll();
        return \array_map(static fn($r) => [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'notes' => $r['notes'],
            'created_at' => $r['created_at'],
            'account_count' => (int) $r['account_count'],
        ], $rows);
    }

    public function create(string $name, ?string $notes = null): int
    {
        $name = \trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Nome é obrigatório.');
        }
        $now = Dates::nowUtcIso();
        $stmt = $this->pdo->prepare(
            'INSERT INTO institution (name, notes, created_at) VALUES (?, ?, ?)',
        );
        $stmt->execute([$name, $notes, $now]);
        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM institution WHERE id = ?');
        $stmt->execute([$id]);
    }
}
