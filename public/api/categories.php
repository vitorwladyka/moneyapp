<?php
declare(strict_types=1);

/**
 * GET    /api/categories.php             -> lista plana
 * GET    /api/categories.php?tree=1      -> árvore
 * POST   /api/categories.php             -> cria
 * DELETE /api/categories.php?id=X        -> exclui (não-sistema, sem filhos/txs)
 */
require_once __DIR__ . '/../../bootstrap.php';

use Moneyapp\Services\CategoryService;
use Moneyapp\Services\Connection;

try {
    $pdo = Connection::pdo();
    $svc = new CategoryService($pdo);

    $method = request_method();
    if ($method === 'GET') {
        if (isset($_GET['tree']) && $_GET['tree'] === '1') {
            json_response($svc->listTree());
        }
        json_response($svc->listFlat());
    }
    if ($method === 'POST') {
        $data = json_input();
        $id = $svc->create(
            $data['name'] ?? '',
            isset($data['parent_id']) ? (int) $data['parent_id'] : null,
            $data['kind'] ?? 'expense',
        );
        json_response(['id' => $id], 201);
    }
    if ($method === 'DELETE') {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            json_error('id é obrigatório.', 400);
        }
        $svc->delete($id);
        json_response(['ok' => true]);
    }
    json_error('Método não suportado.', 405);
} catch (Throwable $e) {
    json_error($e->getMessage(), 400);
}
