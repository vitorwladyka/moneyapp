<?php
declare(strict_types=1);

/**
 * GET    /api/transactions.php               -> lista
 * GET    /api/transactions.php?account_id=X  -> filtra por conta
 * GET    /api/transactions.php?month=YYYY-MM -> filtra por mês
 * GET    /api/transactions.php?show_deleted=1
 * POST   /api/transactions.php               -> cria
 * PATCH  /api/transactions.php?id=X          -> atualiza
 * DELETE /api/transactions.php?id=X          -> soft delete
 * POST   /api/transactions.php?id=X&action=restore -> restaura
 */
require_once __DIR__ . '/../../bootstrap.php';

use Moneyapp\Services\Connection;
use Moneyapp\Services\TransactionService;

try {
    $pdo = Connection::pdo();
    $svc = new TransactionService($pdo);

    $method = request_method();
    if ($method === 'GET') {
        $opts = [
            'account_id' => isset($_GET['account_id']) && $_GET['account_id'] !== '' ? (int) $_GET['account_id'] : null,
            'month' => isset($_GET['month']) && $_GET['month'] !== '' ? $_GET['month'] : null,
            'show_deleted' => isset($_GET['show_deleted']) && $_GET['show_deleted'] === '1',
            'limit' => isset($_GET['limit']) ? (int) $_GET['limit'] : 200,
            'order' => isset($_GET['order']) && $_GET['order'] !== '' ? $_GET['order'] : 'asc',
            'status' => isset($_GET['status']) && $_GET['status'] !== '' ? $_GET['status'] : null,
        ];
        json_response($svc->list($opts));
    }
    if ($method === 'POST' && isset($_GET['id'], $_GET['action']) && $_GET['action'] === 'restore') {
        $svc->restore((int) $_GET['id']);
        json_response(['ok' => true]);
    }
    if ($method === 'POST') {
        $data = json_input();
        $id = $svc->create($data);
        json_response(['id' => $id], 201);
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
    if ($method === 'DELETE') {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            json_error('id é obrigatório.', 400);
        }
        $svc->softDelete($id);
        json_response(['ok' => true]);
    }
    json_error('Método não suportado.', 405);
} catch (Throwable $e) {
    json_error($e->getMessage(), 400);
}
