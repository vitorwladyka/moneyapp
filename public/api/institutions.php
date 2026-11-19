<?php
declare(strict_types=1);

/**
 * GET    /api/institutions.php
 * POST   /api/institutions.php            -> cria
 * DELETE /api/institutions.php?id=X
 */
require_once __DIR__ . '/../../bootstrap.php';

use Moneyapp\Services\Connection;
use Moneyapp\Services\InstitutionService;

try {
    $pdo = Connection::pdo();
    $svc = new InstitutionService($pdo);

    $method = request_method();
    if ($method === 'GET') {
        json_response($svc->list());
    }
    if ($method === 'POST') {
        $data = json_input();
        $id = $svc->create($data['name'] ?? '', $data['notes'] ?? null);
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
