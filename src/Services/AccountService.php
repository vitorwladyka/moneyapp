<?php
declare(strict_types=1);

namespace Moneyapp\Services;

use Moneyapp\Core\Dates;
use Moneyapp\Core\Database;
use Moneyapp\Core\Money;
use Moneyapp\Core\MoneyException;
use Moneyapp\Core\DateException;
use PDO;

/**
 * Service de Contas.
 * Regras (§5):
 *   - opening_balance: saldo no início do dia opening_date.
 *   - opening_date: NOT NULL, data de negócio.
 *   - Alterar opening_date NÃO ajusta opening_balance automaticamente.
 *   - account ON DELETE RESTRICT em transaction; service só exclui conta sem txs ativas.
 *   - is_closed não afeta o cálculo de saldo (§5.3).
 *   - Tipos: bank, credit_card, cash, investment, loan, asset, liability.
 *
 * Saldo exato: calculado via Money::sum (não pela view, que é auxiliar — §5.4).
 */
final class AccountService
{
    private const VALID_TYPES = ['bank', 'credit_card', 'cash', 'investment', 'loan', 'asset', 'liability'];

    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array<mixed>> */
    public function list(): array
    {
        $sql = <<<'SQL'
            SELECT a.id, a.name, a.type, a.institution_id, i.name AS institution_name,
                   a.account_number, a.abbreviation, a.currency, a.opening_balance,
                   a.opening_date, a.minimum_balance, a.interest_rate, a.comment,
                   a.is_favorite, a.is_closed, a.include_in_budget, a.include_in_totals,
                   a.display_order, a.created_at, a.updated_at
            FROM account a
            LEFT JOIN institution i ON i.id = a.institution_id
            ORDER BY a.display_order ASC, a.name COLLATE NOCASE ASC
        SQL;
        $rows = $this->pdo->query($sql)->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $opening = Money::fromStorage($r['opening_balance']);
            $txAmounts = $this->fetchActiveTxAmounts((int) $r['id']);
            $txAmounts[] = $opening;
            $balance = Money::sum($txAmounts);
            $minimum = $r['minimum_balance'] !== null ? Money::fromStorage($r['minimum_balance']) : null;
            $out[] = [
                'id' => (int) $r['id'],
                'name' => $r['name'],
                'type' => $r['type'],
                'institution_id' => $r['institution_id'] !== null ? (int) $r['institution_id'] : null,
                'institution_name' => $r['institution_name'],
                'account_number' => $r['account_number'],
                'abbreviation' => $r['abbreviation'],
                'currency' => $r['currency'],
                'opening_balance_cents' => $opening->toCents(),
                'opening_balance_formatted' => $opening->format(true),
                'balance_cents' => $balance->toCents(),
                'balance_formatted' => $balance->format(true),
                'opening_date' => Dates::formatDate(Dates::fromIso($r['opening_date'])),
                'minimum_balance_cents' => $minimum?->toCents(),
                'minimum_balance_formatted' => $minimum?->format(true),
                'comment' => $r['comment'],
                'is_favorite' => ((int) $r['is_favorite']) === 1,
                'is_closed' => ((int) $r['is_closed']) === 1,
                'include_in_totals' => ((int) $r['include_in_totals']) === 1,
                'display_order' => (int) $r['display_order'],
            ];
        }
        return $out;
    }

    /** @return list<Money> */
    private function fetchActiveTxAmounts(int $accountId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT amount FROM "transaction" WHERE account_id = ? AND deleted_at IS NULL',
        );
        $stmt->execute([$accountId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = Money::fromStorage($r['amount']);
        }
        return $out;
    }

    public function get(int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.*, i.name AS institution_name
             FROM account a
             LEFT JOIN institution i ON i.id = a.institution_id
             WHERE a.id = ?',
        );
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        if ($r === false) {
            throw new \RuntimeException("Conta #{$id} não encontrada.");
        }
        $opening = Money::fromStorage($r['opening_balance']);
        $balance = Money::sum([$opening, ...$this->fetchActiveTxAmounts($id)]);
        return [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'type' => $r['type'],
            'institution_id' => $r['institution_id'] !== null ? (int) $r['institution_id'] : null,
            'institution_name' => $r['institution_name'],
            'opening_balance_cents' => $opening->toCents(),
            'balance_cents' => $balance->toCents(),
            'opening_date' => Dates::formatDate(Dates::fromIso($r['opening_date'])),
            'is_favorite' => ((int) $r['is_favorite']) === 1,
            'is_closed' => ((int) $r['is_closed']) === 1,
        ];
    }

    /**
     * Cria conta.
     * @param array{name:string,type:string,institution_id?:?int,account_number?:?string,
     *     abbreviation?:?string,opening_balance:string,opening_date:string,
     *     minimum_balance?:?string,comment?:?string,is_favorite?:bool} $input
     */
    public function create(array $input): int
    {
        $name = \trim($input['name'] ?? '');
        if ($name === '') {
            throw new \InvalidArgumentException('Nome é obrigatório.');
        }
        $type = $input['type'] ?? '';
        if (!\in_array($type, self::VALID_TYPES, true)) {
            throw new \InvalidArgumentException("Tipo inválido: {$type}");
        }
        // Money e Dates são os únicos pontos de validação de valor/data.
        $openingMoney = Money::parse($input['opening_balance'] ?? '0');
        $openingDate = Dates::parseDate($input['opening_date'] ?? '');
        $minimumMoney = isset($input['minimum_balance']) && $input['minimum_balance'] !== ''
            ? Money::parse($input['minimum_balance'])
            : null;

        $now = Dates::nowUtcIso();
        $stmt = $this->pdo->prepare(<<<'SQL'
            INSERT INTO account
              (name, type, institution_id, account_number, abbreviation, currency,
               opening_balance, opening_date, minimum_balance, comment,
               is_favorite, is_closed, include_in_budget, include_in_totals, display_order,
               created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 'BRL', ?, ?, ?, ?, ?, 0, 1, 1, 0, ?, ?)
        SQL);
        $stmt->execute([
            $name,
            $type,
            $input['institution_id'] ?? null,
            $input['account_number'] ?? null,
            $input['abbreviation'] ?? null,
            $openingMoney->toStorage(),
            Dates::toIso($openingDate),
            $minimumMoney?->toStorage(),
            $input['comment'] ?? null,
            $input['is_favorite'] ?? false ? 1 : 0,
            $now,
            $now,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Atualiza flags e metadados editáveis. Não toca em opening_balance/opening_date.
     * @param array<mixed> $input
     */
    public function update(int $id, array $input): void
    {
        $now = Dates::nowUtcIso();
        $fields = [];
        $params = [];
        foreach (['name', 'type', 'institution_id', 'account_number', 'abbreviation', 'comment'] as $f) {
            if (\array_key_exists($f, $input)) {
                $fields[] = "{$f} = ?";
                $params[] = $input[$f] ?? null;
            }
        }
        foreach (['is_favorite', 'is_closed', 'include_in_totals'] as $f) {
            if (\array_key_exists($f, $input)) {
                $fields[] = "{$f} = ?";
                $params[] = $input[$f] ? 1 : 0;
            }
        }
        if (!$fields) {
            return;
        }
        $fields[] = 'updated_at = ?';
        $params[] = $now;
        $params[] = $id;
        $sql = 'UPDATE account SET ' . \implode(', ', $fields) . ' WHERE id = ?';
        $this->pdo->prepare($sql)->execute($params);
    }

    public function delete(int $id): void
    {
        // Service: só exclui se não há transações ativas (o DB tem RESTRICT mas deletadas
        // não contam para o RESTRICT — só as ativas impedem).
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM "transaction" WHERE account_id = ? AND deleted_at IS NULL',
        );
        $stmt->execute([$id]);
        $count = (int) $stmt->fetchColumn(0);
        if ($count > 0) {
            throw new \RuntimeException(
                "Conta #{$id} possui {$count} transação(ões) ativa(s). Feche a conta em vez de excluir.",
            );
        }
        // Deleta transações soft-deletadas e depois a conta.
        $this->pdo->prepare('DELETE FROM "transaction" WHERE account_id = ? AND deleted_at IS NOT NULL')->execute([$id]);
        $this->pdo->prepare('DELETE FROM account WHERE id = ?')->execute([$id]);
    }
}
