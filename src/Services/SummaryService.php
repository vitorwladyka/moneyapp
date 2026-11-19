<?php
declare(strict_types=1);

namespace Moneyapp\Services;

use Moneyapp\Core\Dates;
use Moneyapp\Core\Money;
use Moneyapp\Services\AccountService;
use Moneyapp\Services\CategoryService;
use Moneyapp\Services\TransactionService;
use PDO;

/**
 * Service de agregações para o Dashboard.
 *
 * Regras (§5.4):
 *   - Saldo agregado exato deve usar Money::sum, NÃO a view account_balance (que é auxiliar).
 *   - A view é para conveniência de consulta; o service lê amounts individuais.
 */
final class SummaryService
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<mixed> */
    public function dashboard(): array
    {
        $assetTypes = ['bank', 'cash', 'investment', 'asset'];
        $liabilityTypes = ['credit_card', 'loan', 'liability'];

        $now = Dates::nowUtc();
        $todayIso = Dates::toIso($now->setTime(0, 0, 0));

        // Contas não fechadas + transações ativas (com data para cortar "até hoje").
        $sql = <<<'SQL'
            SELECT a.id, a.type, a.opening_balance, t.amount, t.date AS tx_date
            FROM account a
            LEFT JOIN "transaction" t ON t.account_id = a.id AND t.deleted_at IS NULL
            WHERE a.is_closed = 0
            ORDER BY a.id
        SQL;
        $rows = $this->pdo->query($sql)->fetchAll();
        $byAccount = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            if (!isset($byAccount[$id])) {
                $byAccount[$id] = [
                    'type' => $r['type'],
                    'opening' => $r['opening_balance'],
                    'amounts_all' => [],
                    'amounts_today' => [],
                ];
            }
            if ($r['amount'] !== null) {
                $byAccount[$id]['amounts_all'][] = $r['amount'];
                // date <= hoje (inclusive)
                if (($r['tx_date'] ?? '') !== '' && $r['tx_date'] <= $todayIso) {
                    $byAccount[$id]['amounts_today'][] = $r['amount'];
                }
            }
        }

        $assetsFinal = [];
        $liabsFinal = [];
        $assetsToday = [];
        $liabsToday = [];
        foreach ($byAccount as $acc) {
            $itemsFinal = [Money::fromStorage($acc['opening'])];
            foreach ($acc['amounts_all'] as $amt) {
                $itemsFinal[] = Money::fromStorage($amt);
            }
            $balanceFinal = Money::sum($itemsFinal);

            $itemsToday = [Money::fromStorage($acc['opening'])];
            foreach ($acc['amounts_today'] as $amt) {
                $itemsToday[] = Money::fromStorage($amt);
            }
            $balanceToday = Money::sum($itemsToday);

            if (\in_array($acc['type'], $assetTypes, true)) {
                $assetsFinal[] = $balanceFinal;
                $assetsToday[] = $balanceToday;
            } else {
                $liabsFinal[] = $balanceFinal;
                $liabsToday[] = $balanceToday;
            }
        }
        $totalAssetsFinal = Money::sum($assetsFinal);
        $totalLiabFinal = Money::sum($liabsFinal);
        $netWorthFinal = Money::sum([$totalAssetsFinal, $totalLiabFinal]);

        $totalAssetsToday = Money::sum($assetsToday);
        $totalLiabToday = Money::sum($liabsToday);
        $netWorthToday = Money::sum([$totalAssetsToday, $totalLiabToday]);

        // Compat: campos sem sufixo = final (com futuros), como antes.
        $totalAssets = $totalAssetsFinal;
        $totalLiab = $totalLiabFinal;
        $netWorth = $netWorthFinal;

        // Mês atual.
        $yyyy = (int) $now->format('Y');
        $mm = (int) $now->format('n');
        $monthStartIso = \sprintf('%04d-%02d-01', $yyyy, $mm);
        $mNext = $mm + 1;
        $yNext = $yyyy;
        if ($mNext > 12) {
            $mNext = 1;
            $yNext++;
        }
        $monthEndIso = \sprintf('%04d-%02d-01', $yNext, $mNext);

        $stmt = $this->pdo->prepare(
            'SELECT amount FROM "transaction" WHERE deleted_at IS NULL AND date >= ? AND date < ?',
        );
        $stmt->execute([$monthStartIso, $monthEndIso]);
        $monthIncomes = [];
        $monthExpenses = [];
        foreach ($stmt->fetchAll() as $r) {
            $m = Money::fromStorage($r['amount']);
            if ($m->isPositive()) {
                $monthIncomes[] = $m;
            } else {
                $monthExpenses[] = $m;
            }
        }
        $monthIncome = Money::sum($monthIncomes);
        $monthExpense = Money::sum($monthExpenses);
        $monthBalance = Money::sum([$monthIncome, $monthExpense]);

        // Label do mês em pt-BR (ex.: "novembro '26").
        // Usa IntlDateFormatter quando a extensão intl está disponível;
        // caso contrário (PHP compilado sem intl), usa fallback manual
        // com o nome do mês em pt-BR — evita HTTP 500 no /api/summary.php.
        $monthLabel = self::formatMonthLabel($now);

        return [
            'total_assets_cents' => $totalAssets->toCents(),
            'total_assets_formatted' => $totalAssets->format(true),
            'total_liabilities_cents' => $totalLiab->toCents(),
            'total_liabilities_formatted' => $totalLiab->format(true),
            'net_worth_cents' => $netWorth->toCents(),
            'net_worth_formatted' => $netWorth->format(true),
            // Até hoje (date <= hoje)
            'total_assets_today_cents' => $totalAssetsToday->toCents(),
            'total_assets_today_formatted' => $totalAssetsToday->format(true),
            'total_liabilities_today_cents' => $totalLiabToday->toCents(),
            'total_liabilities_today_formatted' => $totalLiabToday->format(true),
            'net_worth_today_cents' => $netWorthToday->toCents(),
            'net_worth_today_formatted' => $netWorthToday->format(true),
            // Final com futuros
            'total_assets_final_cents' => $totalAssetsFinal->toCents(),
            'total_assets_final_formatted' => $totalAssetsFinal->format(true),
            'total_liabilities_final_cents' => $totalLiabFinal->toCents(),
            'total_liabilities_final_formatted' => $totalLiabFinal->format(true),
            'net_worth_final_cents' => $netWorthFinal->toCents(),
            'net_worth_final_formatted' => $netWorthFinal->format(true),
            'month_income_cents' => $monthIncome->toCents(),
            'month_income_formatted' => $monthIncome->format(true),
            'month_expense_cents' => $monthExpense->toCents(),
            'month_expense_formatted' => $monthExpense->format(true),
            'month_balance_cents' => $monthBalance->toCents(),
            'month_balance_formatted' => $monthBalance->format(true),
            'month_label' => $monthLabel,
        ];
    }

    /**
     * Despesas por categoria (top-level) do mês corrente.
     * @return list<array{name:string,cents:int,formatted:string}>
     */
    public function categorySpending(?string $month = null): array
    {
        $where = 't.deleted_at IS NULL AND t.amount < 0';
        $params = [];
        if ($month !== null && \preg_match('/\A[0-9]{4}-[0-9]{2}\z/', $month) === 1) {
            [$yyyy, $mm] = \explode('-', $month);
            $start = \sprintf('%04d-%02d-01', (int) $yyyy, (int) $mm);
            $mNext = (int) $mm + 1;
            $yNext = (int) $yyyy;
            if ($mNext > 12) {
                $mNext = 1;
                $yNext++;
            }
            $end = \sprintf('%04d-%02d-01', $yNext, $mNext);
            $where .= ' AND t.date >= ? AND t.date < ?';
            $params[] = $start;
            $params[] = $end;
        }
        $sql = <<<SQL
            SELECT c.id, c.name, c.parent_id, pc.name AS parent_name, t.amount
            FROM "transaction" t
            JOIN category c ON c.id = t.category_id
            LEFT JOIN category pc ON pc.id = c.parent_id
            WHERE {$where}
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $totals = [];
        foreach ($stmt->fetchAll() as $r) {
            $topName = $r['parent_name'] ?? $r['name'];
            $money = Money::fromStorage($r['amount']);
            if (!isset($totals[$topName])) {
                $totals[$topName] = Money::zero();
            }
            $totals[$topName] = Money::sum([$totals[$topName], $money]);
        }
        $out = [];
        foreach ($totals as $name => $m) {
            $abs = Money::fromCents(\abs($m->toCents()));
            $out[] = [
                'name' => $name,
                'cents' => $abs->toCents(),
                'formatted' => $abs->format(true),
            ];
        }
        \usort($out, static fn($a, $b) => $b['cents'] <=> $a['cents']);
        return $out;
    }

    /**
     * Matriz de despesas por categoria × últimos N meses.
     * Pais totalizam as subcategorias. Valores em valor absoluto (despesa).
     *
     * @return array{months:list<array{key:string,label:string}>,rows:list<array>}
     */
    public function categoryMonthMatrix(int $months = 6): array
    {
        $now = Dates::nowUtc();
        $monthKeys = [];
        $monthMeta = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $year = (int) $now->format('Y');
            $month = (int) $now->format('n') - $i;
            while ($month <= 0) {
                $month += 12;
                $year--;
            }
            $key = \sprintf('%04d-%02d', $year, $month);
            $start = $key . '-01';
            $mNext = $month + 1;
            $yNext = $year;
            if ($mNext > 12) {
                $mNext = 1;
                $yNext++;
            }
            $end = \sprintf('%04d-%02d-01', $yNext, $mNext);
            $dateRef = \DateTimeImmutable::createFromFormat('!Y-m-d', $start, new \DateTimeZone('UTC'));
            $label = self::formatTrendLabel($dateRef !== false ? $dateRef : $now, $month, $year);
            $monthKeys[] = $key;
            $monthMeta[] = ['key' => $key, 'label' => $label, 'start' => $start, 'end' => $end];
        }

        $rangeStart = $monthMeta[0]['start'];
        $rangeEnd = $monthMeta[\count($monthMeta) - 1]['end'];

        // Todas as categorias (para montar árvore mesmo sem gasto).
        $catRows = $this->pdo->query(
            'SELECT id, name, parent_id, kind FROM category ORDER BY kind, display_order, name COLLATE NOCASE',
        )->fetchAll();
        $cats = [];
        foreach ($catRows as $c) {
            $id = (int) $c['id'];
            $cats[$id] = [
                'id' => $id,
                'name' => $c['name'],
                'parent_id' => $c['parent_id'] !== null ? (int) $c['parent_id'] : null,
                'kind' => $c['kind'],
            ];
        }

        // Gastos por categoria × mês (só despesas, amount < 0).
        $stmt = $this->pdo->prepare(
            'SELECT t.category_id, substr(t.date, 1, 7) AS ym, t.amount
             FROM "transaction" t
             WHERE t.deleted_at IS NULL AND t.amount < 0
               AND t.category_id IS NOT NULL
               AND t.date >= ? AND t.date < ?',
        );
        $stmt->execute([$rangeStart, $rangeEnd]);

        // leafTotals[catId][ym] = Money (já em valor absoluto acumulado)
        $leafAbs = [];
        foreach ($stmt->fetchAll() as $r) {
            $cid = (int) $r['category_id'];
            $ym = $r['ym'];
            if (!\in_array($ym, $monthKeys, true)) {
                continue;
            }
            $abs = Money::fromCents(\abs(Money::fromStorage($r['amount'])->toCents()));
            if (!isset($leafAbs[$cid][$ym])) {
                $leafAbs[$cid][$ym] = Money::zero();
            }
            $leafAbs[$cid][$ym] = Money::sum([$leafAbs[$cid][$ym], $abs]);
        }

        // Agrega filhos no pai (só um nível; se houver neto, sobe ao avô também).
        $rollAbs = $leafAbs;
        foreach ($cats as $id => $c) {
            $pid = $c['parent_id'];
            if ($pid === null || !isset($leafAbs[$id])) {
                continue;
            }
            foreach ($leafAbs[$id] as $ym => $money) {
                if (!isset($rollAbs[$pid][$ym])) {
                    $rollAbs[$pid][$ym] = Money::zero();
                }
                $rollAbs[$pid][$ym] = Money::sum([$rollAbs[$pid][$ym], $money]);
            }
        }

        $packMonths = static function (array $byYm, array $monthKeys): array {
            $out = [];
            $totalItems = [];
            foreach ($monthKeys as $k) {
                if (isset($byYm[$k])) {
                    $m = $byYm[$k];
                    $out[$k] = [
                        'cents' => $m->toCents(),
                        'formatted' => $m->format(true),
                    ];
                    $totalItems[] = $m;
                } else {
                    $out[$k] = ['cents' => 0, 'formatted' => '—'];
                }
            }
            $tot = Money::sum($totalItems);
            return [
                'months' => $out,
                'total' => [
                    'cents' => $tot->toCents(),
                    'formatted' => $tot->isZero() ? '—' : $tot->format(true),
                ],
            ];
        };

        // Monta árvore: roots + children (só categorias expense com algum valor no período, ou com filho com valor).
        $childrenOf = [];
        foreach ($cats as $id => $c) {
            if ($c['parent_id'] !== null) {
                $childrenOf[$c['parent_id']][] = $id;
            }
        }

        $hasActivity = static function (int $id) use ($rollAbs, $childrenOf): bool {
            if (!empty($rollAbs[$id])) {
                return true;
            }
            foreach ($childrenOf[$id] ?? [] as $childId) {
                if (!empty($rollAbs[$childId])) {
                    return true;
                }
            }
            return false;
        };

        $rows = [];
        foreach ($cats as $id => $c) {
            if ($c['parent_id'] !== null) {
                continue;
            }
            if ($c['kind'] !== 'expense') {
                continue;
            }
            if (!$hasActivity($id)) {
                continue;
            }
            $packed = $packMonths($rollAbs[$id] ?? [], $monthKeys);
            $childRows = [];
            foreach ($childrenOf[$id] ?? [] as $childId) {
                if (empty($rollAbs[$childId])) {
                    continue;
                }
                $cp = $packMonths($rollAbs[$childId] ?? [], $monthKeys);
                $childRows[] = [
                    'id' => $childId,
                    'name' => $cats[$childId]['name'],
                    'is_parent' => false,
                    'months' => $cp['months'],
                    'total' => $cp['total'],
                ];
            }
            \usort($childRows, static fn($a, $b) => $b['total']['cents'] <=> $a['total']['cents']);
            $rows[] = [
                'id' => $id,
                'name' => $c['name'],
                'is_parent' => true,
                'months' => $packed['months'],
                'total' => $packed['total'],
                'children' => $childRows,
            ];
        }
        \usort($rows, static fn($a, $b) => $b['total']['cents'] <=> $a['total']['cents']);

        return [
            'months' => \array_map(
                static fn(array $m): array => ['key' => $m['key'], 'label' => $m['label']],
                $monthMeta,
            ),
            'rows' => $rows,
        ];
    }

    /**
     * Tendência mensal: receita x despesa dos últimos N meses.
     * @return list<array{label:string,income_cents:int,expense_cents:int,balance_cents:int}>
     */
    public function monthlyTrend(int $months = 6): array
    {
        $now = Dates::nowUtc();
        $out = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $year = (int) $now->format('Y');
            $month = (int) $now->format('n') - $i;
            while ($month <= 0) {
                $month += 12;
                $year--;
            }
            $start = \sprintf('%04d-%02d-01', $year, $month);
            $mNext = $month + 1;
            $yNext = $year;
            if ($mNext > 12) {
                $mNext = 1;
                $yNext++;
            }
            $end = \sprintf('%04d-%02d-01', $yNext, $mNext);
            $stmt = $this->pdo->prepare(
                'SELECT amount FROM "transaction" WHERE deleted_at IS NULL AND date >= ? AND date < ?',
            );
            $stmt->execute([$start, $end]);
            $incs = [];
            $exps = [];
            foreach ($stmt->fetchAll() as $r) {
                $m = Money::fromStorage($r['amount']);
                if ($m->isPositive()) {
                    $incs[] = $m;
                } else {
                    $exps[] = $m;
                }
            }
            $income = Money::sum($incs);
            $expense = Money::sum($exps);
            $balance = Money::sum([$income, $expense]);

            $dateRef = \DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                $start,
                new \DateTimeZone('UTC'),
            );
            // Label "MMM/yy" em pt-BR (ex.: "nov/26"). Fallback manual sem intl.
            $label = self::formatTrendLabel($dateRef, $month, $year);

            $out[] = [
                'label' => $label,
                'income_cents' => $income->toCents(),
                'expense_cents' => $expense->toCents(),
                'balance_cents' => $balance->toCents(),
            ];
        }
        return $out;
    }

    // ============================================================
    // Helpers de formatação de mês em pt-BR.
    // Preferem IntlDateFormatter (extensão intl). Se intl não estiver
    // disponível, usam fallback manual — evita HTTP 500 no dashboard
    // em ambientes minimalistas (PHP sem intl, ex.: alpine images).
    // ============================================================

    /** Nomes longos dos meses em pt-BR lowercase, índice 1-12. */
    private const MONTHS_PT_BR = [
        1  => 'janeiro',  2  => 'fevereiro', 3 => 'março',     4 => 'abril',
        5  => 'maio',     6  => 'junho',     7 => 'julho',     8 => 'agosto',
        9  => 'setembro', 10 => 'outubro',   11 => 'novembro', 12 => 'dezembro',
    ];

    /** Abreviações de mês em pt-BR (3 letras), índice 1-12. */
    private const MONTHS_SHORT_PT_BR = [
        1  => 'jan', 2 => 'fev', 3 => 'mar', 4 => 'abr',
        5  => 'mai', 6 => 'jun', 7 => 'jul', 8 => 'ago',
        9  => 'set', 10 => 'out', 11 => 'nov', 12 => 'dez',
    ];

    /**
     * Rótulo de mês longo: "novembro '26" (quando intl) ou "novembro 2026" (fallback).
     * Recebe DateTimeImmutable em UTC.
     */
    private static function formatMonthLabel(\DateTimeImmutable $date): string
    {
        if (\class_exists(\IntlDateFormatter::class)) {
            $formatter = \IntlDateFormatter::create(
                'pt_BR',
                \IntlDateFormatter::FULL,
                \IntlDateFormatter::NONE,
                'UTC',
                \IntlDateFormatter::GREGORIAN,
                "MMMM yyyy",
            );
            if ($formatter !== false) {
                $formatted = $formatter->format($date);
                if (\is_string($formatted) && $formatted !== '') {
                    return $formatted;
                }
            }
        }
        // Fallback manual pt-BR.
        $m = (int) $date->format('n');
        $y = (int) $date->format('Y');
        return (self::MONTHS_PT_BR[$m] ?? $date->format('F')) . ' ' . $y;
    }

    /**
     * Rótulo de mês curto para tendência: "nov/26".
     * Recebe DateTimeImmutable em UTC + mês/ano numéricos (já calculados).
     */
    private static function formatTrendLabel(\DateTimeImmutable $date, int $month, int $year): string
    {
        if (\class_exists(\IntlDateFormatter::class)) {
            $formatter = \IntlDateFormatter::create(
                'pt_BR',
                \IntlDateFormatter::FULL,
                \IntlDateFormatter::NONE,
                'UTC',
                \IntlDateFormatter::GREGORIAN,
                'MMM/yy',
            );
            if ($formatter !== false) {
                $formatted = $formatter->format($date);
                if (\is_string($formatted) && $formatted !== '') {
                    return $formatted;
                }
            }
        }
        // Fallback manual pt-BR: "nov/26".
        $short = self::MONTHS_SHORT_PT_BR[$month] ?? \sprintf('%02d', $month);
        return $short . '/' . \sprintf('%02d', $year % 100);
    }
}
