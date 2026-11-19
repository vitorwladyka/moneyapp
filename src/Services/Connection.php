<?php
declare(strict_types=1);

namespace Moneyapp\Services;

use Moneyapp\Core\Database;
use Moneyapp\Core\DatabaseException;
use PDO;

/**
 * Container singleton para a conexão PDO.
 * Garante que só existe UMA instância de PDO por request (new PDO só em Database::connect).
 */
final class Connection
{
    private static ?PDO $instance = null;

    public static function pdo(): PDO
    {
        if (self::$instance === null) {
            self::$instance = Database::connect();
            // Aplica o schema APENAS se o banco estiver vazio (sem tabelas).
            // NÃO chamar createSchema em todo request: ele abre uma transação
            // de escrita (beginTransaction), e sob concorrência (5 requests
            // simultâneos no dashboard via Promise.all) SQLite devolve
            // SQLITE_BUSY "database is locked" para os perdedores.
            // Como o schema usa CREATE TABLE IF NOT EXISTS, é idempotente,
            // mas o LOCK não é — então pulamos quando já está aplicado.
            $tableCount = (int) self::$instance
                ->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")
                ->fetchColumn(0);
            if ($tableCount === 0) {
                Database::createSchema(self::$instance);
            }
        }
        return self::$instance;
    }

    /** Para testes: injeta uma PDO específica (ex.: ':memory:'). */
    public static function setForTest(PDO $pdo): void
    {
        self::$instance = $pdo;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }
}
