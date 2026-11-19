<?php
/**
 * MoneyApp — bootstrap.
 *
 * - Autoload PSR-4 via Composer (se disponível) ou fallback spl_autoload.
 * - Define constantes de caminho.
 * - Carrega helpers de resposta JSON usados por todos os endpoints /api/*.php
 *   (json_response, json_error, json_input, request_method).
 * - Não inicia sessão, não abre conexão (lazy: só quando Database::connect é chamado).
 */

declare(strict_types=1);

// Caminhos absolutos do projeto.
define('MONEYAPP_ROOT', dirname(__FILE__));
define('MONEYAPP_SRC', MONEYAPP_ROOT . '/src');
define('MONEYAPP_SQL', MONEYAPP_ROOT . '/sql/schema.sql');
define('MONEYAPP_DATA_DIR', MONEYAPP_ROOT . '/data');
define('MONEYAPP_DB_PATH', MONEYAPP_DATA_DIR . '/moneyapp.db');

// Autoload: prefer Composer; fallback simples para PSR-4 se não instalado.
if (is_file(MONEYAPP_ROOT . '/vendor/autoload.php')) {
    require_once MONEYAPP_ROOT . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Moneyapp\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $file = MONEYAPP_SRC . '/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
}

// Helpers de resposta JSON para endpoints /api/*.php.
// Carregado no bootstrap para garantir que json_response(), json_error(),
// json_input() e request_method() estejam sempre disponíveis — sem isso,
// qualquer catch block que chame json_error() gera fatal error
// "Call to undefined function json_error()" e devolve HTML em vez de JSON,
// quebrando o frontend (que cai no catch do fetch e devolve objeto vazio).
$helpersPath = MONEYAPP_ROOT . '/public/api/_helpers.php';
if (is_file($helpersPath)) {
    require_once $helpersPath;
}

// Garante que data/ existe (Database::connect também faz isso, mas adianta).
if (!is_dir(MONEYAPP_DATA_DIR)) {
    @mkdir(MONEYAPP_DATA_DIR, 0775, true);
}
