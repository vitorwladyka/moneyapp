# MoneyApp v2.0 — Docker image
# PHP 8.2 + Apache + pdo_sqlite (PDO com SQLite do sistema).
# A app usa exclusivamente PDO (new PDO('sqlite:...')); a extensão PHP
# `sqlite3` (classe SQLite3) não é necessária e não é instalada.
# Binário `sqlite3` CLI é instalado via apt-get apenas para inspeção manual.
# Uso: ver docker-compose.yml e seção "Executar via Docker" no README.md.

FROM php:8.2-apache

LABEL org.opencontainers.image.title="MoneyApp"
LABEL org.opencontainers.image.description="Gerenciador de finanças pessoais (PHP puro + SQLite)"
LABEL org.opencontainers.image.version="2.0"
LABEL org.opencontainers.image.licenses="proprietary"

# Pacotes APT:
#   - libsqlite3-dev: headers para compilar pdo_sqlite (precisa do sqlite3.pc
#     para o pkg-config). Pode ser purgado com --auto-remove depois, pois
#     libsqlite3-0 (runtime) já vem na imagem base.
#   - libicu-dev: headers para compilar a extensão intl (IntlDateFormatter,
#     usada para rótulos de mês em pt-BR no dashboard).
#     NÃO purgar (nem com --auto-remove nem com apt-get autoremove):
#     isso removeria as libs runtime libicu*76 (libicuuc76, libicui18n76,
#     libicuio76) que intl.so precisa em runtime, quebrando o startup do
#     PHP com "libicuio.so.76: cannot open shared object file".
#     Custo: ~30MB na imagem — vale a pena pela robustez.
#   - sqlite3: binário CLI para inspeção via docker compose exec.
#   - curl: usado pelo healthcheck.
#   - ca-certificates: HTTPS para apt/curl.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libsqlite3-dev \
        libicu-dev \
        sqlite3 \
        curl \
        ca-certificates \
    && docker-php-ext-install pdo_sqlite intl \
    && apt-get purge -y --auto-remove libsqlite3-dev \
    && rm -rf /var/lib/apt/lists/*

# Configurações PHP para produção/teste:
#   - display_errors = Off: erros não vão para o body da resposta HTTP
#     (evita warnings PHP quebrando JSON; ApiResponse fica limpa).
#   - display_startup_errors = Off: warnings de carregamento de extensões
#     (ex.: "Unable to load dynamic library 'intl'") não vão para o body.
#     Crítico: SEM essa opção, uma warning de startup polui TODA resposta
#     HTTP e quebra o parse JSON no frontend, causando erro
#     "Cannot read properties of undefined (reading 'split')" no dashboard.
#   - log_errors = On + error_log = /dev/stderr: erros vão para o log do
#     Apache/Docker (visíveis via `docker compose logs moneyapp`).
COPY docker/php-errors.ini /usr/local/etc/php/conf.d/zz-moneyapp-errors.ini

# Habilita mod_rewrite (não obrigatório para o MVP — URLs são /index.php —
# mas é útil para rotas futuras e customizações).
RUN a2enmod rewrite

# DocumentRoot aponta para public/ (definido no vhost abaixo).
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf

# Permissões: o usuário www-data (uid 33) precisa escrever em data/.
# Criamos o diretório aqui para garantir existência mesmo se volume vazio.
RUN mkdir -p /var/www/html/data && chown -R www-data:www-data /var/www/html/data

# Copia o código da aplicação. O .dockerignore filtra vendor/, tests/, etc.
COPY --chown=www-data:www-data . /var/www/html/

# Garante permissões finais: dono www-data nos arquivos da app e escrita em data/.
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R u+rwX,g+rwX /var/www/html/data

# entrypoint ajusta permissões do volume montado e inicia Apache em foreground.
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
