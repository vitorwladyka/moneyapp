<?php
declare(strict_types=1);

namespace Moneyapp\Services;

use Moneyapp\Core\Dates;
use Moneyapp\Core\Money;
use PDO;

/**
 * Service de Categorias.
 * Regras:
 *   - Hierárquica via parent_id self-FK (ON DELETE RESTRICT).
 *   - UNIQUE em (parent_id, name) COLLATE NOCASE.
 *   - kind ∈ {'income','expense','transfer'}.
 */
final class CategoryService
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array{id:int,name:string,parent_id:?int,parent_name:?string,kind:string,is_system:bool,display_order:int}> */
    public function listFlat(): array
    {
        $sql = <<<'SQL'
            SELECT c.id, c.name, c.parent_id, p.name AS parent_name,
                   c.kind, c.is_system, c.display_order
            FROM category c
            LEFT JOIN category p ON p.id = c.parent_id
            ORDER BY c.kind ASC, c.display_order ASC, c.name COLLATE NOCASE ASC
        SQL;
        $rows = $this->pdo->query($sql)->fetchAll();
        return \array_map(static fn($r) => [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'parent_id' => $r['parent_id'] !== null ? (int) $r['parent_id'] : null,
            'parent_name' => $r['parent_name'],
            'kind' => $r['kind'],
            'is_system' => ((int) $r['is_system']) === 1,
            'display_order' => (int) $r['display_order'],
        ], $rows);
    }

    /** @return list<array{node:array,children:list}> */
    public function listTree(): array
    {
        $flat = $this->listFlat();
        $byId = [];
        foreach ($flat as $c) {
            $byId[$c['id']] = ['node' => $c, 'children' => []];
        }
        $roots = [];
        foreach ($flat as $c) {
            if ($c['parent_id'] !== null && isset($byId[$c['parent_id']])) {
                $byId[$c['parent_id']]['children'][] = &$byId[$c['id']];
            } else {
                $roots[] = &$byId[$c['id']];
            }
        }
        return $roots;
    }

    public function create(string $name, ?int $parentId, string $kind): int
    {
        $name = \trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Nome é obrigatório.');
        }
        if (!\in_array($kind, ['income', 'expense', 'transfer'], true)) {
            throw new \InvalidArgumentException("kind inválido: {$kind}");
        }
        // Se tem parent, herda o kind do pai (e ignora o informado).
        if ($parentId !== null) {
            $stmt = $this->pdo->prepare('SELECT kind FROM category WHERE id = ?');
            $stmt->execute([$parentId]);
            $parent = $stmt->fetch();
            if ($parent === false) {
                throw new \InvalidArgumentException('Categoria pai não encontrada.');
            }
            $kind = $parent['kind'];
        }
        $now = Dates::nowUtcIso();
        $stmt = $this->pdo->prepare(
            'INSERT INTO category (name, parent_id, kind, is_system, display_order, created_at)
             VALUES (?, ?, ?, 0, 0, ?)',
        );
        $stmt->execute([$name, $parentId, $kind, $now]);
        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('SELECT id, name, is_system FROM category WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new \RuntimeException('Categoria não encontrada.');
        }
        if ((int) $row['is_system'] === 1) {
            throw new \RuntimeException('Categorias de sistema não podem ser excluídas.');
        }

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM category WHERE parent_id = ?');
        $stmt->execute([$id]);
        $children = (int) $stmt->fetchColumn();
        if ($children > 0) {
            throw new \RuntimeException(
                'Não é possível excluir: esta categoria tem ' . $children
                . ' subcategoria(s). Remova ou mova as subcategorias antes.',
            );
        }

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM "transaction" WHERE category_id = ?');
        $stmt->execute([$id]);
        $txCount = (int) $stmt->fetchColumn();
        if ($txCount > 0) {
            throw new \RuntimeException(
                'Não é possível excluir: há ' . $txCount
                . ' transação(ões) usando esta categoria.',
            );
        }

        // Payees com default_category_id — schema é ON DELETE SET NULL, mas avisamos se quiser.
        $stmt = $this->pdo->prepare('DELETE FROM category WHERE id = ? AND is_system = 0');
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Falha ao excluir a categoria.');
        }
    }
}
