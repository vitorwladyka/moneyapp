<?php
declare(strict_types=1);

/**
 * Helper de resposta JSON e parse de input.
 * Sem framework: lê php://input para POST/PUT/DELETE JSON.
 */

function json_response(mixed $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, must-revalidate');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $message, int $status = 400, array $extra = []): void
{
    json_response(['error' => $message, ...$extra], $status);
}

/** Lê JSON body do request (POST/PUT/DELETE). */
function json_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        json_error('Corpo JSON inválido.', 400);
    }
    return $data;
}

/** Método HTTP atual (normalizado). */
function request_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}
