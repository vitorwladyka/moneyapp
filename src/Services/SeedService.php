<?php
declare(strict_types=1);

namespace Moneyapp\Services;

use Moneyapp\Core\Dates;
use Moneyapp\Core\Database;
use Moneyapp\Core\Money;
use PDO;

/**
 * SeedService — popula o banco com dados de demonstração se estiver vazio.
 *
 * Princípio da spec (§1): "O banco nasce populado com dados do usuário (a forma
 * como isso acontece não é escopo do produto)". Aqui é só para demonstração.
 *
 * Datas relativas ao mês atual, para o dashboard mostrar dados vivos.
 */
final class SeedService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function ensureSeeded(): void
    {
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM account')->fetchColumn(0);
        if ($count > 0) {
            return;
        }
        Database::transactional($this->pdo, function (PDO $pdo): void {
            $this->seedAll($pdo);
        });
    }

    private function seedAll(PDO $pdo): void
    {
        // ----- Instituições -----
        $itau = $this->insert($pdo, 'institution', ['name' => 'Itaú Unibanco', 'notes' => 'Banco principal', 'created_at' => Dates::nowUtcIso()]);
        $nubank = $this->insert($pdo, 'institution', ['name' => 'Nubank', 'notes' => 'Cartão digital', 'created_at' => Dates::nowUtcIso()]);
        $caixa = $this->insert($pdo, 'institution', ['name' => 'Caixa Econômica Federal', 'notes' => null, 'created_at' => Dates::nowUtcIso()]);
        $xp = $this->insert($pdo, 'institution', ['name' => 'XP Investimentos', 'notes' => null, 'created_at' => Dates::nowUtcIso()]);

        // ----- Categorias -----
        $now = Dates::nowUtcIso();
        $catSalario = $this->insert($pdo, 'category', ['name' => 'Salário', 'parent_id' => null, 'kind' => 'income', 'is_system' => 0, 'display_order' => 1, 'created_at' => $now]);
        $catRend = $this->insert($pdo, 'category', ['name' => 'Rendimentos', 'parent_id' => null, 'kind' => 'income', 'is_system' => 0, 'display_order' => 2, 'created_at' => $now]);
        $catOutrosRend = $this->insert($pdo, 'category', ['name' => 'Outras rendas', 'parent_id' => null, 'kind' => 'income', 'is_system' => 0, 'display_order' => 3, 'created_at' => $now]);

        $catMoradia = $this->insert($pdo, 'category', ['name' => 'Moradia', 'parent_id' => null, 'kind' => 'expense', 'is_system' => 0, 'display_order' => 10, 'created_at' => $now]);
        $catAluguel = $this->insert($pdo, 'category', ['name' => 'Aluguel', 'parent_id' => $catMoradia, 'kind' => 'expense', 'is_system' => 0, 'display_order' => 1, 'created_at' => $now]);
        $catCond = $this->insert($pdo, 'category', ['name' => 'Condomínio', 'parent_id' => $catMoradia, 'kind' => 'expense', 'is_system' => 0, 'display_order' => 2, 'created_at' => $now]);
        $catEnergia = $this->insert($pdo, 'category', ['name' => 'Energia', 'parent_id' => $catMoradia, 'kind' => 'expense', 'is_system' => 0, 'display_order' => 3, 'created_at' => $now]);
        $catAgua = $this->insert($pdo, 'category', ['name' => 'Água', 'parent_id' => $catMoradia, 'kind' => 'expense', 'is_system' => 0, 'display_order' => 4, 'created_at' => $now]);

        $catAlim = $this->insert($pdo, 'category', ['name' => 'Alimentação', 'parent_id' => null, 'kind' => 'expense', 'is_system' => 0, 'display_order' => 20, 'created_at' => $now]);
        $catSuper = $this->insert($pdo, 'category', ['name' => 'Supermercado', 'parent_id' => $catAlim, 'kind' => 'expense', 'is_system' => 0, 'display_order' => 1, 'created_at' => $now]);
        $catRest = $this->insert($pdo, 'category', ['name' => 'Restaurantes', 'parent_id' => $catAlim, 'kind' => 'expense', 'is_system' => 0, 'display_order' => 2, 'created_at' => $now]);

        $catTransp = $this->insert($pdo, 'category', ['name' => 'Transporte', 'parent_id' => null, 'kind' => 'expense', 'is_system' => 0, 'display_order' => 30, 'created_at' => $now]);
        $catCombust = $this->insert($pdo, 'category', ['name' => 'Combustível', 'parent_id' => $catTransp, 'kind' => 'expense', 'is_system' => 0, 'display_order' => 1, 'created_at' => $now]);
        $catApp = $this->insert($pdo, 'category', ['name' => 'Aplicativos', 'parent_id' => $catTransp, 'kind' => 'expense', 'is_system' => 0, 'display_order' => 2, 'created_at' => $now]);

        $catLazer = $this->insert($pdo, 'category', ['name' => 'Lazer', 'parent_id' => null, 'kind' => 'expense', 'is_system' => 0, 'display_order' => 40, 'created_at' => $now]);
        $catSaude = $this->insert($pdo, 'category', ['name' => 'Saúde', 'parent_id' => null, 'kind' => 'expense', 'is_system' => 0, 'display_order' => 50, 'created_at' => $now]);
        $catEdu = $this->insert($pdo, 'category', ['name' => 'Educação', 'parent_id' => null, 'kind' => 'expense', 'is_system' => 0, 'display_order' => 60, 'created_at' => $now]);

        // ----- Payees -----
        $payeesData = [
            ['Empresa ABC Ltda', $catSalario],
            ['Imobiliária Centro', null],
            ['Condomínio Ed. Aurora', null],
            ['Enel SP', null],
            ['Sabesp', null],
            ['Pão de Açúcar', null],
            ['Carrefour', null],
            ['Restaurante Mamma Mia', null],
            ['iFood', null],
            ['Shell Posto Aurora', null],
            ['99 Taxis', null],
            ['Uber', null],
            ['Netflix', null],
            ['Spotify', null],
            ['Cinemark', null],
            ['Drogasil', null],
            ['Hospital São Luiz', null],
            ['Alura Cursos', null],
            ['XP Investimentos', $catRend],
            ['Tesouro Direto', $catRend],
        ];
        $payeeIds = [];
        foreach ($payeesData as [$pname, $pcat]) {
            $payeeIds[] = $this->insert($pdo, 'payee', [
                'name' => $pname,
                'default_category_id' => $pcat,
                'notes' => null,
                'created_at' => $now,
            ]);
        }

        // Mês atual e anterior.
        $now = Dates::nowUtc();
        $curY = (int) $now->format('Y');
        $curM = (int) $now->format('n');
        [$prevY, $prevM] = $curM === 1 ? [$curY - 1, 12] : [$curY, $curM - 1];
        $today = (int) $now->format('j');

        // Helper para data ISO.
        $iso = static function (int $y, int $m, int $d): string {
            return \sprintf('%04d-%02d-%02d', $y, $m, $d);
        };
        $prevMonthName = $this->monthName($prevM);
        $curMonthName = $this->monthName($curM);

        // ----- Contas -----
        $accCorrente = $this->insertAccount($pdo, 'Conta Corrente Itaú', 'bank', $itau, 'CC Itaú', 5000, $iso($curY - 1, 1, 1), true, 1);
        $accCartao = $this->insertAccount($pdo, 'Cartão Nubank', 'credit_card', $nubank, 'Nubank', 0, $iso($curY - 1, 1, 1), false, 2);
        $accPoupanca = $this->insertAccount($pdo, 'Poupança Caixa', 'bank', $caixa, 'Poup Caixa', 12000, $iso($curY - 1, 6, 1), false, 3);
        $accInvest = $this->insertAccount($pdo, 'Conta XP Investimentos', 'investment', $xp, 'XP', 25000, $iso($curY - 1, 1, 15), false, 4);
        $accCash = $this->insertAccount($pdo, 'Carteira', 'cash', null, null, 300, $iso($curY, $curM, 1), false, 5);

        // ----- Transações: mês anterior -----
        $txsPrev = [
            [$accCorrente, $iso($prevY, $prevM, 5), 8500.00, $payeeIds[0], $catSalario, "Salário {$prevMonthName}", 'cleared'],
            [$accCorrente, $iso($prevY, $prevM, 10), -1800.00, $payeeIds[1], $catAluguel, "Aluguel {$prevMonthName}", 'cleared'],
            [$accCorrente, $iso($prevY, $prevM, 10), -420.00, $payeeIds[2], $catCond, "Condomínio {$prevMonthName}", 'cleared'],
            [$accCorrente, $iso($prevY, $prevM, 12), -185.47, $payeeIds[3], $catEnergia, "Conta de luz {$prevMonthName}", 'cleared'],
            [$accCorrente, $iso($prevY, $prevM, 12), -78.30, $payeeIds[4], $catAgua, "Conta de água {$prevMonthName}", 'cleared'],
            [$accCorrente, $iso($prevY, $prevM, 15), -632.18, $payeeIds[5], $catSuper, 'Compras quinzenais', 'cleared'],
            [$accCorrente, $iso($prevY, $prevM, 22), -287.55, $payeeIds[6], $catSuper, 'Compras fim de semana', 'none'],
            [$accCartao, $iso($prevY, $prevM, 8), -98.50, $payeeIds[7], $catRest, 'Jantar', 'cleared'],
            [$accCartao, $iso($prevY, $prevM, 14), -45.90, $payeeIds[8], $catRest, 'iFood almoço', 'cleared'],
            [$accCartao, $iso($prevY, $prevM, 11), -250.00, $payeeIds[9], $catCombust, 'Tanque cheio', 'cleared'],
            [$accCartao, $iso($prevY, $prevM, 18), -22.30, $payeeIds[10], $catApp, 'Corrida 99', 'none'],
            [$accCartao, $iso($prevY, $prevM, 5), -55.90, $payeeIds[12], $catLazer, 'Netflix mensal', 'cleared'],
            [$accCartao, $iso($prevY, $prevM, 5), -21.90, $payeeIds[13], $catLazer, 'Spotify mensal', 'cleared'],
            [$accCartao, $iso($prevY, $prevM, 20), -89.00, $payeeIds[14], $catLazer, 'Cinema com família', 'none'],
            [$accCartao, $iso($prevY, $prevM, 16), -134.75, $payeeIds[15], $catSaude, 'Medicamentos', 'cleared'],
            [$accCorrente, $iso($prevY, $prevM, 7), -89.90, $payeeIds[17], $catEdu, 'Curso online', 'cleared'],
            [$accInvest, $iso($prevY, $prevM, 30), 215.40, $payeeIds[18], $catRend, "Rendimento {$prevMonthName}", 'cleared'],
        ];
        foreach ($txsPrev as $tx) {
            $this->insertTx($pdo, $tx[0], $tx[1], $tx[2], $tx[3], $tx[4], $tx[5], $tx[6]);
        }

        // ----- Transações: mês atual (parcial, até hoje) -----
        $txsCur = [
            [$accCorrente, $iso($curY, $curM, min(5, $today)), 8500.00, $payeeIds[0], $catSalario, "Salário {$curMonthName}", 'cleared'],
        ];
        if ($today >= 8) {
            $txsCur[] = [$accCartao, $iso($curY, $curM, 8), -1181.47, $payeeIds[6], $catSuper, 'Compra mensal', 'none'];
        }
        if ($today >= 9) {
            $txsCur[] = [$accCartao, $iso($curY, $curM, 9), -65.40, $payeeIds[8], $catRest, 'Almoço iFood', 'none'];
        }
        if ($today >= 10) {
            $txsCur[] = [$accCorrente, $iso($curY, $curM, 10), -1800, $payeeIds[1], $catAluguel, "Aluguel {$curMonthName}", 'cleared'];
            $txsCur[] = [$accCorrente, $iso($curY, $curM, 10), -420, $payeeIds[2], $catCond, "Condomínio {$curMonthName}", 'cleared'];
        }
        if ($today >= 11) {
            $txsCur[] = [$accCartao, $iso($curY, $curM, 11), -260, $payeeIds[9], $catCombust, 'Abastecimento', 'none'];
        }
        if ($today >= 12) {
            $txsCur[] = [$accCorrente, $iso($curY, $curM, 12), -167.83, $payeeIds[3], $catEnergia, "Conta de luz {$curMonthName}", 'none'];
        }
        if ($today >= 14) {
            $txsCur[] = [$accInvest, $iso($curY, $curM, 14), 142.18, $payeeIds[19], $catRend, "Rendimento parcial {$curMonthName}", 'none'];
        }
        foreach ($txsCur as $tx) {
            $this->insertTx($pdo, $tx[0], $tx[1], $tx[2], $tx[3], $tx[4], $tx[5], $tx[6]);
        }

        // Uma transação soft-deleted para demonstrar o filtro.
        $softDeletedId = $this->insertTx($pdo, $accCorrente, $iso($prevY, $prevM, 25), -50, $payeeIds[14], $catLazer, 'Cinema (cancelado)', 'none');
        $pdo->prepare('UPDATE "transaction" SET deleted_at = ? WHERE id = ?')->execute([Dates::nowUtcIso(), $softDeletedId]);
    }

    private function insert(PDO $pdo, string $table, array $data): int
    {
        $cols = \implode(', ', \array_keys($data));
        $placeholders = \implode(', ', \array_fill(0, \count($data), '?'));
        $sql = "INSERT INTO {$table} ({$cols}) VALUES ({$placeholders})";
        $pdo->prepare($sql)->execute(\array_values($data));
        return (int) $pdo->lastInsertId();
    }

    private function insertAccount(PDO $pdo, string $name, string $type, ?int $inst, ?string $abbr, float $opening, string $dateIso, bool $fav, int $order): int
    {
        $now = Dates::nowUtcIso();
        $openingMoney = Money::parse((string) $opening);
        $pdo->prepare(<<<'SQL'
            INSERT INTO account
              (name, type, institution_id, account_number, abbreviation, currency,
               opening_balance, opening_date, minimum_balance, interest_rate, comment,
               is_favorite, is_closed, include_in_budget, include_in_totals, display_order,
               created_at, updated_at)
            VALUES (?, ?, ?, NULL, ?, 'BRL', ?, ?, NULL, NULL, NULL, ?, 0, 1, 1, ?, ?, ?)
        SQL)->execute([
            $name, $type, $inst, $abbr,
            $openingMoney->toStorage(), $dateIso,
            $fav ? 1 : 0, $order, $now, $now,
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function insertTx(PDO $pdo, int $accId, string $dateIso, float $amount, ?int $payeeId, int $catId, string $memo, string $status): int
    {
        $now = Dates::nowUtcIso();
        $money = Money::parse((string) $amount);
        $pdo->prepare(<<<'SQL'
            INSERT INTO "transaction"
              (account_id, date, amount, payee_id, category_id, memo, number,
               status, transfer_group_id, is_split, created_at, updated_at, deleted_at)
            VALUES (?, ?, ?, ?, ?, ?, NULL, ?, NULL, 0, ?, ?, NULL)
        SQL)->execute([
            $accId, $dateIso, $money->toStorage(),
            $payeeId, $catId, $memo, $status,
            $now, $now,
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function monthName(int $m): string
    {
        $names = ['janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro'];
        return $names[$m - 1] ?? '';
    }
}
