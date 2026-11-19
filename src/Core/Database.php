<?php
declare(strict_types=1);

namespace Moneyapp\Core;

use PDO;
use PDOException;
use Throwable;

/**
 * Database — espelho de src/Core/Database.php da spec v2.0 (§9).
 *
 * Princípios (DECISÃO FECHADA):
 *   - Database::connect é o ÚNICO ponto que cria conexões (new PDO só aqui — critério 17).
 *   - PRAGMA foreign_keys=ON em toda conexão.
 *   - ATTR_STRINGIFY_FETCHES=false (PDO devolve int/float nativos).
 *   - createSchema: aplica sql/schema.sql em transação, idempotente, view sempre recriada.
 *   - transactional: beginTransaction; fn; commit; rollback em Throwable; sem retry; sem aninhada.
 *   - Sem migrations no MVP.
 */
final class Database
{
    public const MIN_SQLITE_VERSION = '3.35.0';

    /**
     * Caminho padrão do DB: dirname(__DIR__, 2) . '/data/moneyapp.db'
     * (a partir de src/Core/Database.php → raiz do projeto).
     */
    public static function defaultPath(): string
    {
        return dirname(__DIR__, 2) . '/data/moneyapp.db';
    }

    /**
     * Ordem:
     *  1. Verifica extension_loaded('pdo_sqlite'); senão lança.
     *  2. Se path != ':memory:', cria dirname recursivamente se não existir.
     *  3. Abre PDO com ERRMODE_EXCEPTION, FETCH_ASSOC, ATTR_STRINGIFY_FETCHES=false.
     *  4. Executa PRAGMA foreign_keys=ON e confirma que devolve 1.
     *  5. Lê SELECT sqlite_version() e compara com minSqliteVersion via version_compare.
     *
     * @param string|null $path null = defaultPath()
     * @throws PDOException|DatabaseException
     */
    public static function connect(?string $path = null, string $minSqliteVersion = self::MIN_SQLITE_VERSION): PDO
    {
        if (!\extension_loaded('pdo_sqlite')) {
            throw new DatabaseException('Extensão pdo_sqlite não está carregada.');
        }
        $path ??= self::defaultPath();
        if ($path !== ':memory:') {
            $dir = \dirname($path);
            if (!\is_dir($dir)) {
                @\mkdir($dir, 0775, true);
                if (!\is_dir($dir)) {
                    throw new DatabaseException("Não foi possível criar o diretório: {$dir}");
                }
            }
        }
        try {
            $pdo = new PDO('sqlite:' . $path);
        } catch (PDOException $e) {
            throw new DatabaseException('Falha ao abrir SQLite: ' . $e->getMessage(), 0, $e);
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);

        // PRAGMA foreign_keys=ON.
        $fk = $pdo->query('PRAGMA foreign_keys')->fetchColumn(0);
        if ($fk !== 1 && $fk !== '1') {
            $pdo->exec('PRAGMA foreign_keys = ON');
            $fk = $pdo->query('PRAGMA foreign_keys')->fetchColumn(0);
        }
        if ($fk !== 1 && $fk !== '1') {
            throw new DatabaseException('PRAGMA foreign_keys não está ativo.');
        }

        // busy_timeout: SQLite espera até 5000ms por um lock de escrita
        // antes de devolver SQLITE_BUSY "database is locked". Crítico quando
        // múltiplos processos Apache (workers www-data) atendem requests
        // concorrentes que escrevem (ex.: criar transação + atualizar saldo).
        // Default do PDO é 0 (falha imediata), o que quebra o dashboard sob
        // carga concorrente.
        $pdo->exec('PRAGMA busy_timeout = 5000');

        // Versão do SQLite.
        $version = (string) $pdo->query('SELECT sqlite_version()')->fetchColumn(0);
        if (\version_compare($version, $minSqliteVersion) < 0) {
            throw new DatabaseException(
                "SQLite {$version} é inferior ao mínimo exigido {$minSqliteVersion}.",
            );
        }

        return $pdo;
    }

    /**
     * Aplica sql/schema.sql dentro de uma transação, via exec().
     * Idempotente: tabelas/índices com IF NOT EXISTS; view sempre recriada.
     * Tabela existente com definição diferente NÃO é alterada (sem migrations).
     *
     * @throws DatabaseException
     */
    public static function createSchema(PDO $pdo): void
    {
        $sqlPath = dirname(__DIR__, 2) . '/sql/schema.sql';
        $sql = @\file_get_contents($sqlPath);
        if ($sql === false) {
            throw new DatabaseException("schema.sql não encontrado: {$sqlPath}");
        }
        try {
            $pdo->beginTransaction();
            // exec() aceita múltiplas instruções no pdo_sqlite.
            $pdo->exec($sql);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                } catch (Throwable) {
                    // swallow rollback error
                }
            }
            throw new DatabaseException('createSchema falhou: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * beginTransaction; executa $fn($pdo); commit.
     * Em Throwable: rollback (se ativa) e relança. Sem retry. Sem aninhada.
     *
     * @template T
     * @param callable(PDO): T $fn
     * @return T
     * @throws Throwable
     */
    public static function transactional(PDO $pdo, callable $fn): mixed
    {
        if ($pdo->inTransaction()) {
            throw new DatabaseException('Transação aninhada não é suportada.');
        }
        $pdo->beginTransaction();
        try {
            $ret = $fn($pdo);
            $pdo->commit();
            return $ret;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                } catch (Throwable) {
                    // swallow
                }
            }
            throw $e;
        }
    }
}
