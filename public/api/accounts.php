<?php
declare(strict_types=1);

/**
 * GET  /api/accounts.php            -> lista
 * POST /api/accounts.php            -> cria
 * DELETE /api/accounts.php?id=X     -> exclui
 */
require_once __DIR__ . '/../../bootstrap.php';

use Moneyapp\Services\AccountService;
use Moneyapp\Services\Connection;

try {
    $pdo = Connection::pdo();
    $svc = new AccountService($pdo);

    $method = request_method();
    if ($method === 'GET') {
        json_response($svc->list());
    }
    if ($method === 'POST') {
        $data = json_input();
        $id = $svc->create($data);
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
    if ($method === 'PATCH' || $method === 'PUT') {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            json_error('id é obrigatório.', 400);
        }
        $data = json_input();
        $svc->update($id, $data);
        json_response(['ok' => true]);
    }
    json_error('Método não suportado.', 405);
} catch (Throwable $e) {
    json_error($e->getMessage(), 400);
}
