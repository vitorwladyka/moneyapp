<?php
declare(strict_types=1);

/**
 * GET /api/summary.php            -> dashboard summary
 * GET /api/summary.php?chart=trend     -> tendência mensal (últimos 6 meses)
 * GET /api/summary.php?chart=categories -> despesas por categoria (mês corrente)
 * GET /api/summary.php?chart=category_matrix -> categorias × 6 meses (com subcategorias)
 */
require_once __DIR__ . '/../../bootstrap.php';

use Moneyapp\Services\Connection;
use Moneyapp\Services\SummaryService;

try {
    $pdo = Connection::pdo();
    $svc = new SummaryService($pdo);

    $chart = $_GET['chart'] ?? null;
    if ($chart === 'trend') {
        json_response($svc->monthlyTrend(6));
    }
    if ($chart === 'categories') {
        json_response($svc->categorySpending(null));
    }
    if ($chart === 'category_matrix') {
        json_response($svc->categoryMonthMatrix(6));
    }
    json_response($svc->dashboard());
} catch (Throwable $e) {
    json_error('Falha: ' . $e->getMessage(), 500);
}
