<?php
declare(strict_types=1);

/**
 * GET    /api/payees.php
 * POST   /api/payees.php            -> cria
 * PATCH  /api/payees.php?id=X       -> atualiza
 * DELETE /api/payees.php?id=X
 */
require_once __DIR__ . '/../../bootstrap.php';

use Moneyapp\Services\Connection;
use Moneyapp\Services\PayeeService;

try {
    $pdo = Connection::pdo();
    $svc = new PayeeService($pdo);

    $method = request_method();
    if ($method === 'GET') {
        json_response($svc->list());
    }
    if ($method === 'POST') {
        $data = json_input();
        $id = $svc->create(
            $data['name'] ?? '',
            isset($data['default_category_id']) ? (int) $data['default_category_id'] : null,
            $data['notes'] ?? null,
        );
        json_response(['id' => $id], 201);
    }
    if ($method === 'PATCH' || $method === 'PUT') {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            json_error('id é obrigatório.', 400);
        }
        $data = json_input();
        $svc->update(
            $id,
            $data['name'] ?? '',
            \array_key_exists('default_category_id', $data)
                ? ($data['default_category_id'] !== null && $data['default_category_id'] !== ''
                    ? (int) $data['default_category_id']
                    : null)
                : null,
            \array_key_exists('notes', $data) ? ($data['notes'] ?? null) : null,
        );
        json_response(['ok' => true]);
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
