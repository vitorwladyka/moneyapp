<?php
declare(strict_types=1);

namespace Moneyapp\Services;

use Moneyapp\Core\Dates;
use Moneyapp\Core\Money;
use Moneyapp\Core\Database;
use PDO;

/**
 * Service de Transações.
 * Regras (§5, §8.4):
 *   - amount > 0 = crédito (entrada); amount < 0 = débito (saída).
 *   - data >= account.opening_date (service rejeita).
 *   - Soft delete (deleted_at). Nunca DELETE físico.
 *   - is_split = false e category_id obrigatório no MVP (CHECK de coerência).
 *   - transfer_group_id nunca preenchido no MVP.
 *   - status ∈ {'none','cleared','reconciled'}.
 *   - created_at e updated_at pela aplicação (Dates::nowUtcIso()).
 *   - TODO UPDATE atribui updated_at explicitamente (sem trigger).
 */
final class TransactionService
{
    private const VALID_STATUS = ['none', 'cleared', 'reconciled'];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Lista transações.
     * @param array{account_id?:?int,month?:?string,show_deleted?:bool,limit?:int,order?:string} $opts
     *        order: 'asc' (padrão, cronológico) | 'desc' (mais recente primeiro)
     * @return list<array<mixed>>
     */
    public function list(array $opts = []): array
    {
        $where = [];
        $params = [];
        if (($opts['account_id'] ?? null) !== null) {
            $where[] = 't.account_id = ?';
            $params[] = $opts['account_id'];
        }
        if (($opts['show_deleted'] ?? false) !== true) {
            $where[] = 't.deleted_at IS NULL';
        }
        // Status é filtrado depois do saldo corrido (para não distorcer o acumulado).
        $statusFilter = $opts['status'] ?? null;
        if ($statusFilter !== null && $statusFilter !== '' && !\in_array($statusFilter, self::VALID_STATUS, true)) {
            $statusFilter = null;
        }
        $month = $opts['month'] ?? null;
        if ($month !== null && \preg_match('/\A[0-9]{4}-[0-9]{2}\z/', $month) === 1) {
            [$yyyy, $mm] = \explode('-', $month);
            $start = \sprintf('%04d-%02d-01', (int) $yyyy, (int) $mm);
            // Próximo mês.
            $yNext = (int) $yyyy;
            $mNext = (int) $mm + 1;
            if ($mNext > 12) {
                $mNext = 1;
                $yNext++;
            }
            $end = \sprintf('%04d-%02d-01', $yNext, $mNext);
            $where[] = 't.date >= ? AND t.date < ?';
            $params[] = $start;
            $params[] = $end;
        }
        $sql = <<<'SQL'
            SELECT t.id, t.account_id, a.name AS account_name, a.type AS account_type,
                   t.date, t.amount, t.payee_id, p.name AS payee_name,
                   t.category_id, c.name AS category_name, c.parent_id,
                   pc.name AS parent_category_name,
                   t.memo, t.number, t.status, t.created_at, t.updated_at, t.deleted_at
            FROM "transaction" t
            JOIN account a ON a.id = t.account_id
            LEFT JOIN payee p ON p.id = t.payee_id
            LEFT JOIN category c ON c.id = t.category_id
            LEFT JOIN category pc ON pc.id = c.parent_id
        SQL;
        if ($where) {
            $sql .= ' WHERE ' . \implode(' AND ', $where);
        }
        // Ordem: asc = cronológica (saldo corrido); desc = mais recentes primeiro (dashboard).
        $order = \strtolower((string) ($opts['order'] ?? 'asc'));
        if ($order === 'desc') {
            $sql .= ' ORDER BY t.date DESC, t.id DESC';
        } else {
            $sql .= ' ORDER BY t.date ASC, t.id ASC';
        }
        $limit = $opts['limit'] ?? 200;
        $sql .= ' LIMIT ' . (int) $limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // Saldo corrido por conta (ordem ASC). Em "todas as contas", cada conta tem seu próprio acumulador.
        /** @var array<int, Money> $runningByAccount */
        $runningByAccount = [];
        if ($order !== 'desc') {
            // Contas que aparecem no resultado.
            $accountIdsInRows = [];
            foreach ($rows as $r) {
                $accountIdsInRows[(int) $r['account_id']] = true;
            }
            $accountIds = \array_keys($accountIdsInRows);

            // Se filtro de conta única, garante essa conta mesmo sem linhas.
            if (($opts['account_id'] ?? null) !== null) {
                $accountIds[] = (int) $opts['account_id'];
                $accountIds = \array_values(\array_unique($accountIds));
            }

            if ($accountIds) {
                $placeholders = \implode(',', \array_fill(0, \count($accountIds), '?'));
                $stmtOb = $this->pdo->prepare(
                    "SELECT id, opening_balance FROM account WHERE id IN ({$placeholders})",
                );
                $stmtOb->execute($accountIds);
                foreach ($stmtOb->fetchAll() as $acc) {
                    $runningByAccount[(int) $acc['id']] = Money::fromStorage($acc['opening_balance']);
                }

                // Se filtrado por mês, soma transações anteriores ao início do mês em cada conta.
                if ($month !== null && \preg_match('/\A[0-9]{4}-[0-9]{2}\z/', $month) === 1) {
                    [$yyyy, $mm] = \explode('-', $month);
                    $start = \sprintf('%04d-%02d-01', (int) $yyyy, (int) $mm);
                    $stmtPrev = $this->pdo->prepare(
                        "SELECT account_id, amount FROM \"transaction\"
                         WHERE account_id IN ({$placeholders})
                           AND deleted_at IS NULL AND date < ?",
                    );
                    $stmtPrev->execute([...$accountIds, $start]);
                    foreach ($stmtPrev->fetchAll() as $prev) {
                        $aid = (int) $prev['account_id'];
                        if (!isset($runningByAccount[$aid])) {
                            $runningByAccount[$aid] = Money::fromStorage(0);
                        }
                        $runningByAccount[$aid] = Money::sum([
                            $runningByAccount[$aid],
                            Money::fromStorage($prev['amount']),
                        ]);
                    }
                }
            }
        }

        $out = [];
        foreach ($rows as $r) {
            $money = Money::fromStorage($r['amount']);
            $catFull = $r['category_name'] !== null
                ? ($r['parent_category_name'] !== null
                    ? $r['parent_category_name'] . ' › ' . $r['category_name']
                    : $r['category_name'])
                : null;

            $aid = (int) $r['account_id'];
            $balanceCents = null;
            $balanceFormatted = null;
            if (isset($runningByAccount[$aid])) {
                // Só acumula em transações ativas (soft-deleted não alteram o registro).
                if ($r['deleted_at'] === null) {
                    $runningByAccount[$aid] = Money::sum([$runningByAccount[$aid], $money]);
                }
                $balanceCents = $runningByAccount[$aid]->toCents();
                $balanceFormatted = $runningByAccount[$aid]->format(true);
            }

            $out[] = [
                'id' => (int) $r['id'],
                'account_id' => $aid,
                'account_name' => $r['account_name'],
                'account_type' => $r['account_type'],
                'date' => Dates::formatDate(Dates::fromIso($r['date'])),
                'amount_cents' => $money->toCents(),
                'amount_formatted' => $money->format(true),
                'is_credit' => $money->isPositive(),
                'payee_id' => $r['payee_id'] !== null ? (int) $r['payee_id'] : null,
                'payee_name' => $r['payee_name'],
                'category_id' => $r['category_id'] !== null ? (int) $r['category_id'] : null,
                'category_name' => $r['category_name'],
                'category_full' => $catFull,
                'memo' => $r['memo'],
                'number' => $r['number'],
                'status' => $r['status'],
                'deleted_at' => $r['deleted_at'],
                'balance_cents' => $balanceCents,
                'balance_formatted' => $balanceFormatted,
            ];
        }

        // Aplica filtro de status só na saída (saldo corrido já considerou todas as linhas).
        if ($statusFilter !== null && $statusFilter !== '') {
            $out = \array_values(\array_filter(
                $out,
                static fn(array $row): bool => ($row['status'] ?? null) === $statusFilter,
            ));
        }

        return $out;
    }

    /**
     * Cria transação.
     * @param array{account_id:int,date:string,amount:string,payee_id?:?int,
     *     category_id:?int,memo?:?string,number?:?string,status?:string} $input
     */
    public function create(array $input): int
    {
        $accountId = (int) ($input['account_id'] ?? 0);
        if ($accountId <= 0) {
            throw new \InvalidArgumentException('Conta é obrigatória.');
        }
        if (($input['category_id'] ?? null) === null) {
            throw new \InvalidArgumentException('Categoria é obrigatória no MVP (sem split, sem transferência).');
        }
        $status = $input['status'] ?? 'none';
        if (!\in_array($status, self::VALID_STATUS, true)) {
            throw new \InvalidArgumentException("Status inválido: {$status}");
        }

        // Money e Dates são os únicos pontos de validação.
        $money = Money::parse($input['amount']);
        $date = Dates::parseDate($input['date']);

        // Invariante §5.2: data >= opening_date da conta.
        $stmt = $this->pdo->prepare('SELECT opening_date FROM account WHERE id = ?');
        $stmt->execute([$accountId]);
        $openingDateStr = $stmt->fetchColumn(0);
        if ($openingDateStr === false) {
            throw new \RuntimeException("Conta #{$accountId} não encontrada.");
        }
        $openingDate = Dates::fromIso($openingDateStr);
        if ($date < $openingDate) {
            throw new \InvalidArgumentException(
                'Data da transação (' . Dates::formatDate($date) . ') é anterior à data de abertura da conta ('
                . Dates::formatDate($openingDate) . ').',
            );
        }

        $now = Dates::nowUtcIso();
        $stmt = $this->pdo->prepare(<<<'SQL'
            INSERT INTO "transaction"
              (account_id, date, amount, payee_id, category_id, memo, number,
               status, transfer_group_id, is_split, created_at, updated_at, deleted_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, 0, ?, ?, NULL)
        SQL);
        $stmt->execute([
            $accountId,
            Dates::toIso($date),
            $money->toStorage(),
            $input['payee_id'] ?? null,
            (int) $input['category_id'],
            $input['memo'] ?? null,
            $input['number'] ?? null,
            $status,
            $now,
            $now,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<mixed> $input */
    public function update(int $id, array $input): void
    {
        $now = Dates::nowUtcIso();
        $fields = [];
        $params = [];

        if (isset($input['account_id'])) {
            $fields[] = 'account_id = ?';
            $params[] = (int) $input['account_id'];
        }
        if (isset($input['date'])) {
            $fields[] = 'date = ?';
            $params[] = Dates::toIso(Dates::parseDate($input['date']));
        }
        if (isset($input['amount'])) {
            $fields[] = 'amount = ?';
            $params[] = Money::parse($input['amount'])->toStorage();
        }
        if (\array_key_exists('payee_id', $input)) {
            $fields[] = 'payee_id = ?';
            $params[] = $input['payee_id'] ?? null;
        }
        if (\array_key_exists('category_id', $input)) {
            if ($input['category_id'] === null) {
                throw new \InvalidArgumentException('Categoria é obrigatória no MVP.');
            }
            $fields[] = 'category_id = ?';
            $params[] = (int) $input['category_id'];
        }
        if (\array_key_exists('memo', $input)) {
            $fields[] = 'memo = ?';
            $params[] = $input['memo'] ?? null;
        }
        if (\array_key_exists('number', $input)) {
            $fields[] = 'number = ?';
            $params[] = $input['number'] ?? null;
        }
        if (isset($input['status'])) {
            if (!\in_array($input['status'], self::VALID_STATUS, true)) {
                throw new \InvalidArgumentException("Status inválido: {$input['status']}");
            }
            $fields[] = 'status = ?';
            $params[] = $input['status'];
        }
        if (!$fields) {
            return;
        }
        $fields[] = 'updated_at = ?';
        $params[] = $now;
        $params[] = $id;
        $sql = 'UPDATE "transaction" SET ' . \implode(', ', $fields) . ' WHERE id = ?';
        $this->pdo->prepare($sql)->execute($params);
    }

    /** Soft delete — nunca DELETE físico. */
    public function softDelete(int $id): void
    {
        $now = Dates::nowUtcIso();
        $this->pdo->prepare(
            'UPDATE "transaction" SET deleted_at = ?, updated_at = ? WHERE id = ? AND deleted_at IS NULL',
        )->execute([$now, $now, $id]);
    }

    public function restore(int $id): void
    {
        $now = Dates::nowUtcIso();
        $this->pdo->prepare(
            'UPDATE "transaction" SET deleted_at = NULL, updated_at = ? WHERE id = ?',
        )->execute([$now, $id]);
    }
}
