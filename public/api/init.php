<?php
declare(strict_types=1);

/**
 * POST /api/init.php — inicializa banco (cria schema + seed).
 * Idempotente: pode chamar várias vezes.
 */
require_once __DIR__ . '/../../bootstrap.php';

use Moneyapp\Services\Connection;
use Moneyapp\Services\SeedService;

try {
    $pdo = Connection::pdo(); // createSchema é chamado internamente.
    (new SeedService($pdo))->ensureSeeded();
    json_response(['ok' => true, 'message' => 'Banco inicializado com sucesso.']);
} catch (Throwable $e) {
    json_error('Falha ao inicializar: ' . $e->getMessage(), 500);
}
