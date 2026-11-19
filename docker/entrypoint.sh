#!/usr/bin/env bash
# Entrypoint do container MoneyApp.
# Responsabilidades:
#   1. Garantir que /var/www/html/data exista e seja gravável pelo www-data.
#      Necessário quando volume externo (uid do host != uid do container).
#   2. Ajustar dono de todo o código da app para www-data.
#   3. Inicializar o banco (schema + seed) via PHP CLI.
#   4. RE-CHOWN de data/ DEPOIS do seed — crítico: o PHP roda como root
#      no entrypoint, então o arquivo moneyapp.db criado é propriedade
#      do root. Sem o re-chown, o Apache (www-data) não consegue escrever
#      e o SQLite devolve "attempt to write a readonly database".
#   5. Executa o CMD passado (apache2-foreground).

set -euo pipefail

APP_ROOT="/var/www/html"
DATA_DIR="${APP_ROOT}/data"
APACHE_USER="www-data"

echo "[entrypoint] MoneyApp v2.0 — inicializando container..."

# 1. Garante data/ existe e é gravável pelo www-data ANTES do seed
#    (o PHP pode precisar criar o arquivo moneyapp.db aqui).
mkdir -p "${DATA_DIR}"
chown -R "${APACHE_USER}:${APACHE_USER}" "${DATA_DIR}"
chmod -R u+rwX,g+rwX "${DATA_DIR}"

# 2. Ajusta dono de arquivos da aplicação (não do volume data/ em si,
#    que já foi tratado). Isso garante que arquivos recém-copiados em overlays
#    permaneçam legíveis pelo Apache.
chown -R "${APACHE_USER}:${APACHE_USER}" "${APP_ROOT}"

# 3. Inicializa o banco via CLI PHP (chama Connection::pdo, que aplica schema).
#    Equivalente ao POST /api/init.php, mas sem precisar do Apache de pé.
#    Idempotente: tabelas/índices têm IF NOT EXISTS; SeedService::ensureSeeded
#    só insere se o banco estiver vazio.
#    NOTA: este PHP roda como root (o entrypoint executa como root). O arquivo
#    moneyapp.db criado/aberto aqui será propriedade do root. O re-chown no
#    passo 4 abaixo corrige isso para que o Apache (www-data) consiga escrever.
echo "[entrypoint] Inicializando banco SQLite (schema + seed)..."
php -r "
require '/var/www/html/bootstrap.php';
use Moneyapp\Services\Connection;
use Moneyapp\Services\SeedService;
use Moneyapp\Core\Database;
try {
    \$pdo = Connection::pdo();
    (new SeedService(\$pdo))->ensureSeeded();
    echo '[entrypoint] Banco pronto: ' . Database::defaultPath() . PHP_EOL;
} catch (\Throwable \$e) {
    fwrite(STDERR, '[entrypoint] Aviso: ' . \$e->getMessage() . PHP_EOL);
    // Não aborta o container: a app também chama init.php no primeiro request.
}
"

# 4. CRÍTICO: re-chown de data/ DEPOIS do seed.
#    O PHP acima rodou como root, então:
#      - moneyapp.db (recém-criado) é propriedade do root
#      - moneyapp.db-wal, moneyapp.db-shm (WAL mode) também
#    Sem este passo, o Apache (www-data) recebe "readonly database" ao tentar
#    BEGIN TRANSACTION ou INSERT, porque SQLite precisa de write access não
#    só no .db mas também nos arquivos auxiliares -wal/-shm.
chown -R "${APACHE_USER}:${APACHE_USER}" "${DATA_DIR}"
chmod -R u+rwX,g+rwX "${DATA_DIR}"

# Verificação de sanidade: lista dono do .db se existir (debug amigável).
if [ -f "${DATA_DIR}/moneyapp.db" ]; then
    OWNER=$(stat -c '%U:%G' "${DATA_DIR}/moneyapp.db" 2>/dev/null || stat -f '%Su:%Sg' "${DATA_DIR}/moneyapp.db")
    echo "[entrypoint] moneyapp.db dono: ${OWNER} (esperado: ${APACHE_USER}:${APACHE_USER})"
fi

echo "[entrypoint] Iniciando Apache em foreground..."
exec "$@"
