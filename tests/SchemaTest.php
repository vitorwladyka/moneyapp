<?php
declare(strict_types=1);

namespace Moneyapp\Tests;

use Moneyapp\Core\Database;
use Moneyapp\Core\DatabaseException;
use Moneyapp\Core\Dates;
use Moneyapp\Core\Money;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Database::class)]
final class SchemaTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = Database::connect(':memory:');
        Database::createSchema($this->pdo);
    }

    public function test_create_schema_idempotent(): void
    {
        // Chamar 2x não falha.
        Database::createSchema($this->pdo);
        Database::createSchema($this->pdo);
        $this->assertSame(1, (int) $this->pdo->query('SELECT 1')->fetchColumn(0));
    }

    public function test_foreign_keys_pragma_is_on(): void
    {
        $fk = $this->pdo->query('PRAGMA foreign_keys')->fetchColumn(0);
        $this->assertSame(1, (int) $fk);
    }

    public function test_foreign_key_violation_rejected(): void
    {
        $this->pdo->exec("INSERT INTO institution (name, created_at) VALUES ('X', '2025-01-01 00:00:00')");
        // transaction com account_id inexistente deve falhar.
        $this->expectException(\PDOException::class);
        $this->pdo->exec(
            'INSERT INTO "transaction" (account_id, date, amount, category_id, status, is_split, created_at, updated_at)
             VALUES (999, "2025-01-01", 100, NULL, "none", 0, "2025-01-01 00:00:00", "2025-01-01 00:00:00")',
        );
    }

    public function test_date_check_rejects_invalid_format(): void
    {
        $this->pdo->exec("INSERT INTO institution (name, created_at) VALUES ('X', '2025-01-01 00:00:00')");
        $this->pdo->exec("INSERT INTO account (name, type, opening_balance, opening_date, created_at, updated_at) VALUES ('A', 'bank', 0, '2025-01-01', '2025-01-01 00:00:00', '2025-01-01 00:00:00')");
        $this->expectException(\PDOException::class);
        $this->pdo->exec(
            'INSERT INTO "transaction" (account_id, date, amount, category_id, status, is_split, created_at, updated_at)
             VALUES (1, "2025-13-01", 100, NULL, "none", 0, "2025-01-01 00:00:00", "2025-01-01 00:00:00")',
        );
    }

    public function test_transaction_check_requires_category_when_no_transfer(): void
    {
        $this->pdo->exec("INSERT INTO institution (name, created_at) VALUES ('X', '2025-01-01 00:00:00')");
        $this->pdo->exec("INSERT INTO account (name, type, opening_balance, opening_date, created_at, updated_at) VALUES ('A', 'bank', 0, '2025-01-01', '2025-01-01 00:00:00', '2025-01-01 00:00:00')");
        $this->expectException(\PDOException::class);
        $this->pdo->exec(
            'INSERT INTO "transaction" (account_id, date, amount, category_id, status, is_split, transfer_group_id, created_at, updated_at)
             VALUES (1, "2025-01-01", 100, NULL, "none", 0, NULL, "2025-01-01 00:00:00", "2025-01-01 00:00:00")',
        );
    }

    public function test_view_account_balance_excludes_deleted(): void
    {
        $this->pdo->exec("INSERT INTO institution (name, created_at) VALUES ('X', '2025-01-01 00:00:00')");
        $this->pdo->exec("INSERT INTO account (name, type, opening_balance, opening_date, created_at, updated_at) VALUES ('A', 'bank', 1000, '2025-01-01', '2025-01-01 00:00:00', '2025-01-01 00:00:00')");
        $this->pdo->exec("INSERT INTO category (name, kind, created_at) VALUES ('Salário', 'income', '2025-01-01 00:00:00')");
        $this->pdo->exec(
            'INSERT INTO "transaction" (account_id, date, amount, category_id, status, is_split, created_at, updated_at)
             VALUES (1, "2025-01-05", 500, 1, "none", 0, "2025-01-05 00:00:00", "2025-01-05 00:00:00")',
        );
        $this->pdo->exec(
            'INSERT INTO "transaction" (account_id, date, amount, category_id, status, is_split, created_at, updated_at, deleted_at)
             VALUES (1, "2025-01-06", -200, 1, "none", 0, "2025-01-06 00:00:00", "2025-01-06 00:00:00", "2025-01-07 00:00:00")',
        );

        $row = $this->pdo->query('SELECT balance, transaction_count FROM account_balance WHERE id = 1')->fetch();
        $this->assertSame('1500', (string) $row['balance']);
        $this->assertSame(1, (int) $row['transaction_count']);
    }

    public function test_money_round_trip_with_db(): void
    {
        $this->pdo->exec("INSERT INTO institution (name, created_at) VALUES ('X', '2025-01-01 00:00:00')");
        $this->pdo->exec("INSERT INTO account (name, type, opening_balance, opening_date, created_at, updated_at) VALUES ('A', 'bank', 1181.47, '2025-01-01', '2025-01-01 00:00:00', '2025-01-01 00:00:00')");
        $row = $this->pdo->query('SELECT opening_balance FROM account WHERE id = 1')->fetch();
        $back = Money::fromStorage($row['opening_balance']);
        $this->assertTrue(Money::fromDecimalString('1181.47')->equals($back));
    }

    public function test_dates_round_trip_with_db(): void
    {
        $this->pdo->exec("INSERT INTO institution (name, created_at) VALUES ('X', '2025-01-01 00:00:00')");
        $this->pdo->exec("INSERT INTO account (name, type, opening_balance, opening_date, created_at, updated_at) VALUES ('A', 'bank', 0, '2025-03-01', '2025-01-01 00:00:00', '2025-01-01 00:00:00')");
        $row = $this->pdo->query('SELECT opening_date FROM account WHERE id = 1')->fetch();
        $back = Dates::fromIso($row['opening_date']);
        $this->assertSame('2025-03-01', Dates::toIso($back));
        $this->assertSame('01/03/2025', Dates::formatDate($back));
    }

    public function test_transactional_commits_on_success(): void
    {
        Database::transactional($this->pdo, function (PDO $pdo): void {
            $pdo->exec("INSERT INTO institution (name, created_at) VALUES ('X', '2025-01-01 00:00:00')");
        });
        $count = $this->pdo->query('SELECT COUNT(*) FROM institution')->fetchColumn(0);
        $this->assertSame(1, (int) $count);
    }

    public function test_transactional_rolls_back_on_throw(): void
    {
        try {
            Database::transactional($this->pdo, function (PDO $pdo): void {
                $pdo->exec("INSERT INTO institution (name, created_at) VALUES ('X', '2025-01-01 00:00:00')");
                throw new \RuntimeException('boom');
            });
            $this->fail('Deveria ter lançado.');
        } catch (\RuntimeException $e) {
            $count = $this->pdo->query('SELECT COUNT(*) FROM institution')->fetchColumn(0);
            $this->assertSame(0, (int) $count);
        }
    }

    public function test_transactional_rejects_nested(): void
    {
        $this->expectException(DatabaseException::class);
        Database::transactional($this->pdo, function (PDO $pdo): void {
            Database::transactional($pdo, static fn(PDO $p) => null);
        });
    }

    public function test_view_is_recreated_by_create_schema(): void
    {
        // Substitui a view por uma versão diferente.
        $this->pdo->exec('DROP VIEW IF EXISTS account_balance');
        $this->pdo->exec('CREATE VIEW account_balance AS SELECT id, name FROM account');
        $colsBefore = $this->pdo->query('PRAGMA table_info(account_balance)')->fetchAll();
        // Reaplica o schema normativo.
        Database::createSchema($this->pdo);
        $colsAfter = $this->pdo->query('PRAGMA table_info(account_balance)')->fetchAll();
        // A view deve ter sido recriada com as colunas do schema.sql (id, name, type, currency, balance, transaction_count).
        $this->assertGreaterThan(\count($colsBefore), \count($colsAfter));
        $names = \array_column($colsAfter, 'name');
        $this->assertContains('balance', $names);
        $this->assertContains('transaction_count', $names);
    }
}
