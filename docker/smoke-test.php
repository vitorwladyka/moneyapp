#!/usr/bin/env php
<?php
/**
 * Smoke test — rodar dentro do container para diagnosticar o estado da app.
 *
 * Uso:
 *   docker compose exec moneyapp php /var/www/html/docker/smoke-test.php
 *
 * Verifica:
 *   1. PHP version e extensões críticas (pdo_sqlite, intl)
 *   2. Helpers JSON carregados pelo bootstrap (json_response, json_error, ...)
 *   3. Banco SQLite acessível e schema aplicado
 *   4. IntlDateFormatter disponível OU fallback pt-BR funcionando
 *   5. SummaryService::dashboard() retorna month_label (causa do erro
 *      "Cannot read properties of undefined (reading 'split')" no frontend)
 *   6. Endpoints de API devolvem JSON válido (sem warnings HTML antes do {)
 */

declare(strict_types=1);

require_once '/var/www/html/bootstrap.php';

use Moneyapp\Core\Database;
use Moneyapp\Services\Connection;
use Moneyapp\Services\SummaryService;

$exit = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $exit;
    $status = $ok ? '[OK]    ' : '[FAIL]  ';
    echo $status . $label . ($detail !== '' ? " — {$detail}" : '') . PHP_EOL;
    if (!$ok) {
        $exit = 1;
    }
}

echo "=== MoneyApp smoke test ===" . PHP_EOL;
echo "Timestamp: " . date('c') . PHP_EOL;
echo PHP_EOL;

// 1. PHP e extensões
echo "--- PHP ---" . PHP_EOL;
check('PHP version >= 8.2', version_compare(PHP_VERSION, '8.2.0', '>=') ? true : false, PHP_VERSION);
check('Extensão pdo_sqlite', extension_loaded('pdo_sqlite'));
check('Extensão intl', extension_loaded('intl'),
    extension_loaded('intl') ? 'IntlDateFormatter disponível' : 'Faltando — fallback pt-BR será usado');
echo PHP_EOL;

// 2. Helpers JSON
echo "--- Helpers JSON ---" . PHP_EOL;
check('json_response() definida', function_exists('json_response'));
check('json_error() definida', function_exists('json_error'));
check('json_input() definida', function_exists('json_input'));
check('request_method() definida', function_exists('request_method'));
echo PHP_EOL;

// 3. Banco
echo "--- Banco SQLite ---" . PHP_EOL;
try {
    $pdo = Connection::pdo();
    check('Connection::pdo() conectou', true, Database::defaultPath());

    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")
        ->fetchAll(PDO::FETCH_COLUMN);
    $expected = ['institution', 'account', 'category', 'payee', 'transaction',
                 'transaction_split', 'app_setting', 'scheduled_transaction'];
    $missing = array_diff($expected, $tables);
    check('Schema aplicado (8 tabelas)', empty($missing),
        empty($missing) ? implode(', ', $tables) : 'Faltando: ' . implode(', ', $missing));

    $accCount = (int) $pdo->query('SELECT COUNT(*) FROM account')->fetchColumn(0);
    check('Seed aplicado (account > 0)', $accCount > 0, "{$accCount} contas");

    $txCount = (int) $pdo->query('SELECT COUNT(*) FROM "transaction"')->fetchColumn(0);
    check('Transações seed', $txCount > 0, "{$txCount} transações");
} catch (Throwable $e) {
    check('Banco SQLite', false, $e->getMessage());
}
echo PHP_EOL;

// 4. IntlDateFormatter e fallback
echo "--- IntlDateFormatter ---" . PHP_EOL;
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
if (class_exists(IntlDateFormatter::class)) {
    $fmt = IntlDateFormatter::create(
        'pt_BR', IntlDateFormatter::FULL, IntlDateFormatter::NONE,
        'UTC', IntlDateFormatter::GREGORIAN, "MMMM 'y",
    );
    $label = $fmt !== false ? $fmt->format($now) : '';
    check('IntlDateFormatter::format()', is_string($label) && $label !== '', $label ?: 'vazio');
} else {
    // Fallback manual (mesma lógica do SummaryService).
    $months = [1=>'janeiro',2=>'fevereiro',3=>'março',4=>'abril',5=>'maio',6=>'junho',
               7=>'julho',8=>'agosto',9=>'setembro',10=>'outubro',11=>'novembro',12=>'dezembro'];
    $label = ($months[(int)$now->format('n')] ?? $now->format('F')) . ' ' . $now->format('Y');
    check('Fallback pt-BR (intl ausente)', $label !== '', $label);
}
echo PHP_EOL;

// 5. SummaryService::dashboard()
echo "--- SummaryService::dashboard() ---" . PHP_EOL;
try {
    $svc = new SummaryService($pdo ?? Connection::pdo());
    $dash = $svc->dashboard();

    check('dashboard() executou sem throw', true);

    $hasLabel = isset($dash['month_label']) && is_string($dash['month_label']) && $dash['month_label'] !== '';
    check('month_label presente e não-vazia', $hasLabel, $hasLabel ? $dash['month_label'] : 'AUSENTE');

    if ($hasLabel) {
        $split = explode(' ', $dash['month_label']);
        check('month_label.split(" ")[0] funciona', count($split) > 0, $split[0]);
    }

    check('total_assets_formatted presente', isset($dash['total_assets_formatted']), $dash['total_assets_formatted'] ?? '');
    check('month_income_formatted presente', isset($dash['month_income_formatted']), $dash['month_income_formatted'] ?? '');

    // Simula a resposta JSON exata do /api/summary.php
    $json = json_encode($dash, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $startsWithBrace = str_starts_with(trim($json), '{');
    check('JSON serializa começando com {', $startsWithBrace);
    echo "  Resposta JSON (primeiros 200 chars): " . PHP_EOL;
    echo "  " . substr($json, 0, 200) . "..." . PHP_EOL;
} catch (Throwable $e) {
    check('dashboard() executou sem throw', false, $e->getMessage());
}
echo PHP_EOL;

// 6. Verifica monthlyTrend e categorySpending (outros endpoints do summary)
echo "--- Outros endpoints summary ---" . PHP_EOL;
try {
    $svc = new SummaryService($pdo ?? Connection::pdo());
    $trend = $svc->monthlyTrend(6);
    check('monthlyTrend() retorna 6 itens', is_array($trend) && count($trend) === 6,
        is_array($trend) ? count($trend) . ' itens' : 'não é array');

    if (is_array($trend) && !empty($trend)) {
        $first = $trend[0];
        check('monthlyTrend item tem label', isset($first['label']), $first['label'] ?? '');
    }

    $cats = $svc->categorySpending(null);
    check('categorySpending() retorna array', is_array($cats), is_array($cats) ? count($cats) . ' categorias' : '');
} catch (Throwable $e) {
    check('Outros endpoints summary', false, $e->getMessage());
}
echo PHP_EOL;

echo "=== Resultado: " . ($exit === 0 ? 'PASSOU' : 'FALHOU') . " ===" . PHP_EOL;
exit($exit);
