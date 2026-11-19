# MoneyApp v2.0 — Gerenciador de Finanças Pessoais

Aplicativo de finanças pessoais (inspirado no Microsoft Money), de uso individual e local.
**Backend:** PHP 8.2+ puro (sem framework/ORM), SQLite via PDO.
**Frontend:** HTML + JavaScript puro (sem framework).
**Moeda:** BRL única. **Idioma:** pt-BR.

Construído seguindo a especificação MoneyApp v2.0 (migração de Python/SQLAlchemy/PySide6 → PHP puro).

---

## Stack — DECISÃO FECHADA

| Item | Escolha |
|---|---|
| Linguagem | PHP 8.2+, `declare(strict_types=1);` em todo arquivo |
| Framework | Nenhum |
| Dependências de runtime | Nenhuma além das extensões padrão. Única exigida: `pdo_sqlite` |
| Composer | Apenas autoload PSR-4 + PHPUnit (dev) |
| Banco | SQLite >= 3.35, arquivo único `data/moneyapp.db` |
| Acesso a dados | PDO (driver `sqlite`), SQL escrito à mão, sempre prepared statements |
| Valores monetários | Objeto `Money` imutável, centavos em `int` de 64 bits. **Nunca `float`** |
| Representação no SQLite | `NUMERIC(14,2)` (SQLite não honra estrito; exatidão garantida pela app) |
| Datas | `DateTimeImmutable` sempre em UTC. Hora `00:00:00` para data de negócio |
| Datas de negócio no banco | `TEXT` ISO `YYYY-MM-DD` |
| Timestamps técnicos no banco | `TEXT` ISO `YYYY-MM-DD HH:MM:SS` UTC, sem microssegundos |
| Gravação de timestamps | Pela aplicação (via `Dates::nowUtcIso()`). Nunca `DEFAULT`/`CURRENT_TIMESTAMP` |
| Formatação de valor | pt-BR manual (`R$ 1.181,47`), sem `NumberFormatter`/`setlocale`/`number_format` |
| Formatação de data na UI | pt-BR (`DD/MM/AAAA`) |
| Testes | PHPUnit 11 (apenas dev) |

---

## Estrutura de pastas

```
moneyapp/
├── composer.json
├── phpunit.xml
├── .gitignore
├── README.md                     # este arquivo
├── bootstrap.php                 # autoload PSR-4 + constantes de caminho
├── sql/
│   └── schema.sql                # DDL normativo (spec §8.2), aplicado por Database::createSchema
├── src/
│   ├── Core/
│   │   ├── Money.php             # §6 — Money imutável em centavos
│   │   ├── MoneyException.php
│   │   ├── Dates.php             # §7 — Datas UTC
│   │   ├── DateException.php
│   │   ├── Database.php          # §9 — connect / createSchema / transactional
│   │   └── DatabaseException.php
│   └── Services/                 # Fase 2 — lógica de negócio
│       ├── Connection.php        # singleton PDO (new PDO só em Database::connect)
│       ├── AccountService.php
│       ├── CategoryService.php
│       ├── InstitutionService.php
│       ├── PayeeService.php
│       ├── SeedService.php       # popula o banco com dados de demonstração
│       ├── SummaryService.php    # agregações para o dashboard
│       └── TransactionService.php
├── public/                       # document root (PHP built-in server)
│   ├── index.php                 # Dashboard
│   ├── transactions.php          # Lista de transações
│   ├── accounts.php              # Contas
│   ├── categories.php            # Categorias (árvore)
│   ├── payees.php                # Beneficiários
│   ├── institutions.php          # Instituições
│   ├── api/                      # endpoints JSON para AJAX
│   │   ├── _helpers.php          # json_response(), json_input(), request_method()
│   │   ├── init.php              # POST — cria schema + seed
│   │   ├── summary.php           # GET — dashboard + charts
│   │   ├── accounts.php
│   │   ├── transactions.php
│   │   ├── categories.php
│   │   ├── payees.php
│   │   └── institutions.php
│   ├── assets/
│   │   ├── styles.css            # CSS puro, sem framework
│   │   ├── components.js         # helpers: el(), api(), openModal(), toast()
│   │   └── app.js                # lógica de cada view (vanilla JS)
│   └── partials/
│       ├── header.php
│       └── footer.php
├── tests/
│   ├── MoneyTest.php             # cobre gramática de parse do §6
│   ├── DatesTest.php             # cobre regex estrito + checkdate
│   └── SchemaTest.php            # cobre DDL, CHECKs, view, FK, round trip
└── data/                         # SQLite db (criado pela app, gitignored)
    └── .gitkeep
```

---

## Requisitos

- PHP **8.2 ou superior** (testado com 8.2, 8.3)
- Extensão `pdo_sqlite` (verificada em runtime por `Database::connect`)
- SQLite >= 3.35 (verificado em runtime)
- Composer 2 (para instalar PHPUnit em dev)
- **Não exige:** `bcmath`, `intl`, `mbstring`

Verifique:
```bash
php -v
php -m | grep -i pdo_sqlite
```

---

## Instalação

```bash
cd moneyapp/

# 1. Instalar dependências (PHPUnit em dev; sem pacotes de runtime).
composer install

# 2. (Opcional) Garantir permissão de escrita em data/.
mkdir -p data
chmod 0775 data

# 3. Inicializar o banco (cria schema + dados de demonstração).
#    Pode ser via HTTP ou via init.php na linha de comando:
php public/api/init.php
# ou via curl depois de iniciar o servidor (passo abaixo).
```

---

## Rodar a aplicação

Use o servidor embutido do PHP:

```bash
composer serve
# ou
php -S localhost:8080 -t public
```

Abra <http://localhost:8080/> no navegador.

Na **primeira visita**, o frontend chama automaticamente `POST /api/init.php`, que:
1. Cria `data/moneyapp.db` (se não existir)
2. Aplica `sql/schema.sql` (idempotente)
3. Popula com dados de demonstração (4 instituições, 5 contas, 17 categorias, 20 beneficiários, ~26 transações distribuídas no mês anterior e no corrente)

---

## Executar via Docker (recomendado para teste)

A aplicação roda em um container Apache + PHP 8.2 + SQLite, sem necessidade de instalar PHP
ou SQLite na sua máquina. A configuração está em `Dockerfile`, `docker-compose.yml` e
`docker/` (vhost Apache + entrypoint).

### Pré-requisitos

- Docker 20.10+ e Docker Compose v2 (`docker compose` já vem com o Docker Desktop).

### Subir o container

No diretório raiz do projeto:

```bash
docker compose up --build
```

Acesse: <http://localhost:8080/>

Na **primeira inicialização do container** o `entrypoint.sh` já cria o schema
e popula o banco com os dados de demonstração (mesmo efeito do `POST /api/init.php`).
Você verá no log do container a linha:

```
[entrypoint] Banco pronto: /var/www/html/data/moneyapp.db
```

### Persistência de dados

O arquivo `data/moneyapp.db` é persistido no host via volume (`./data:/var/www/html/data`).
Parar e subir o container novamente mantém todos os dados.

Para **resetar** (apagar tudo e recriar o seed):

```bash
docker compose down
rm -f data/moneyapp.db data/moneyapp.db-* data/moneyapp.db-wal data/moneyapp.db-shm
docker compose up --build
```

### Comandos úteis

```bash
# Logs em tempo real
docker compose logs -f moneyapp

# Reiniciar
docker compose restart moneyapp

# Parar
docker compose down

# Rodar os testes PHPUnit dentro de um container efêmero
docker compose run --rm moneyapp php /var/www/html/vendor/bin/phpunit
# (requer composer install prévio na imagem de dev — não incluído na imagem de runtime)

# Inspecionar o banco SQLite dentro do container
docker compose exec moneyapp sqlite3 /var/www/html/data/moneyapp.db ".tables"
docker compose exec moneyapp sqlite3 /var/www/html/data/moneyapp.db "SELECT COUNT(*) FROM transaction;"
```

### O que a imagem inclui / exclui

| Inclui | Excluído (via `.dockerignore`) |
|---|---|
| `bootstrap.php`, `public/`, `src/`, `sql/`, `composer.json` | `vendor/` (não há dependências de runtime) |
| `docker/` configs e entrypoint | `tests/`, `phpunit.xml` (imagem de runtime) |
| Extensão PHP `pdo_sqlite` (compilada no build, linka contra libsqlite3 do sistema) | `data/*.db*` (montado como volume) |
| Binários `sqlite3` (CLI) + `curl` (healthcheck) | `.git/`, `*.md`, `vendor/` |
| Apache + mod_rewrite | `libsqlite3-dev` (purgado após compilar `pdo_sqlite`) |

### Detalhes da imagem

- **Base:** `php:8.2-apache` (Debian).
- **DocumentRoot:** `/var/www/html/public` (definido em `docker/apache-vhost.conf`).
- **Permissões:** usuário Apache `www-data` escreve em `/var/www/html/data`.
- **Porta:** `80` no container, mapeada para `8080` no host (configurável em `docker-compose.yml`).
- **Timezone:** `America/Sao_Paulo` (para logs do container — a aplicação grava timestamps em UTC internamente).
- **Healthcheck:** `curl http://localhost/` — status `healthy` após ~10s.

### Variáveis e customização

Edite `docker-compose.yml` para mudar a porta do host (ex.: `8090:80`) ou o timezone.
Para usar outro DB path, ajuste a constante `MONEYAPP_DB_PATH` em `bootstrap.php`
e o volume mount correspondente.

### Diagnóstico — se o dashboard não carregar

Se o frontend mostrar "Erro ao carregar dashboard: Cannot read properties of
undefined (reading 'split')", o problema é o `/api/summary.php` devolvendo JSON
inválido (warnings PHP poluindo o body). Diagnóstico direto:

```bash
# 1. Verifique se a API devolve JSON puro (sem warnings HTML antes do {):
docker compose exec moneyapp curl -s http://localhost/api/summary.php | head -c 200
# Esperado: {"total_assets_cents":...,"month_label":"novembro '26",...}
# Se aparecer "<br /><b>Warning</b>..." antes do {, há warnings PHP poluindo
# a resposta — verifique se docker/php-errors.ini está no container.

# 2. Verifique se intl está carregada:
docker compose exec moneyapp php -m | grep -i intl
# Deve listar "intl". Se não listar, há problema no build (libs ICU runtime).

# 3. Verifique os logs do Apache:
docker compose logs moneyapp | grep -i warning
```

Após mudanças no Dockerfile ou em arquivos PHP, sempre faça rebuild limpo:

```bash
docker compose down
docker rmi moneyapp:2.0 2>/dev/null || true
docker compose build --no-cache
docker compose up
```

---

## Rodar os testes

```bash
composer test
# ou
./vendor/bin/phpunit
```

Os testes cobrem os critérios de aceitação da Fase 1 (spec §10):
- Gramática completa de `Money::parse` (aceita/rejeita listas do §6)
- Round-trip `Money::toStorage` ↔ `Money::fromStorage` (incluindo float de `SUM` com resíduo)
- Datas impossíveis (`30/02/2025`) rejeitadas
- `PRAGMA foreign_keys` ativo
- `CHECK` de data rejeita `2025-13-01`
- `CHECK` de coerência exige `category_id` quando `is_split=0` e `transfer_group_id IS NULL`
- View `account_balance` exclui soft-deleted
- `Database::createSchema` idempotente e recria a view
- `transactional` rollback em Throwable, rejeita aninhada

---

## Endpoints HTTP

| Método | Rota | Descrição |
|---|---|---|
| GET | `/` | Dashboard (HTML) |
| GET | `/transactions.php` | Lista de transações (HTML) |
| GET | `/accounts.php` | Contas (HTML) |
| GET | `/categories.php` | Categorias (HTML) |
| GET | `/payees.php` | Beneficiários (HTML) |
| GET | `/institutions.php` | Instituições (HTML) |
| POST | `/api/init.php` | Cria schema + seed |
| GET | `/api/summary.php` | Dashboard (JSON) |
| GET | `/api/summary.php?chart=trend` | Tendência mensal (JSON) |
| GET | `/api/summary.php?chart=categories` | Despesas por categoria (JSON) |
| GET | `/api/accounts.php` | Lista contas com saldo (JSON) |
| POST | `/api/accounts.php` | Cria conta (JSON body) |
| DELETE | `/api/accounts.php?id=X` | Exclui conta (só se não há txs ativas) |
| GET | `/api/transactions.php` | Lista (filtros: `account_id`, `month=YYYY-MM`, `show_deleted=1`, `limit`) |
| POST | `/api/transactions.php` | Cria transação (JSON body) |
| PATCH | `/api/transactions.php?id=X` | Atualiza transação |
| DELETE | `/api/transactions.php?id=X` | Soft delete |
| POST | `/api/transactions.php?id=X&action=restore` | Restaura soft-deleted |
| GET | `/api/categories.php` | Lista plana |
| GET | `/api/categories.php?tree=1` | Árvore hierárquica |
| POST | `/api/categories.php` | Cria categoria |
| GET | `/api/payees.php` | Lista beneficiários |
| POST | `/api/payees.php` | Cria beneficiário |
| DELETE | `/api/payees.php?id=X` | Exclui (ON DELETE SET NULL em transactions) |
| GET | `/api/institutions.php` | Lista instituições |
| POST | `/api/institutions.php` | Cria instituição |
| DELETE | `/api/institutions.php?id=X` | Exclui (ON DELETE SET NULL em accounts) |

---

## Regras de negócio (espelho da spec)

### Sinais e saldo (§5)
- `amount > 0` → entrada (crédito); `amount < 0` → saída (débito)
- Saldo = `opening_balance + SUM(amount)` das transações não deletadas
- Cartão de crédito / empréstimo: saldo normalmente **negativo**
- Investimento: saldo normalmente **positivo**

### Soft delete (§5.4, §8.5)
- `transaction` nunca é deletada fisicamente; apenas `deleted_at` é preenchido
- Queries financeiras filtram `deleted_at IS NULL`
- Pode ser restaurada

### Coerência de transaction (§8.2 — CHECK)
- `(transfer_group_id IS NULL AND category_id IS NOT NULL AND is_split = 0)` → caso normal (MVP)
- `(transfer_group_id IS NULL AND category_id IS NULL AND is_split = 1)` → split (fora do MVP)
- `(transfer_group_id IS NOT NULL AND category_id IS NULL AND is_split = 0)` → transferência (fora do MVP)
- No MVP: toda transação tem `category_id` preenchido, `is_split = 0`, `transfer_group_id IS NULL`

### Exclusões (§8.5)
- `account`: `ON DELETE RESTRICT` em `transaction.account_id`. Service só exclui se não há transações ativas. Caso contrário, fechar via `is_closed = 1`.
- `category`: `ON DELETE RESTRICT` em `parent_id` e em `transaction.category_id`
- `payee`: `ON DELETE SET NULL` em `transaction.payee_id`
- `institution`: `ON DELETE SET NULL` em `account.institution_id`

### Validação de data vs. conta (§5.2)
- `TransactionService::create` rejeita `date < account.opening_date`
- A view `account_balance` **não** filtra por `opening_date` (invariante garantida pelo service)

### Timestamps (§8.0, §9)
- `created_at` e `updated_at` são `NOT NULL` sem `DEFAULT`. Service sempre atribui via `Dates::nowUtcIso()`.
- `updated_at` é atribuído em todo UPDATE (sem trigger).
- `deleted_at` é nullable, preenchido pelo service no soft delete.

### Money: gramática de `parse` (§6)
**Aceita:** `'1.181,47'`, `'1181,47'`, `'0,5'`, `'1181.47'`, `'1,234.56'`, `'1.000.000,00'`, `'R$ 1.181,47'`, `'-180,00'`, `'+50,00'`, `'-R$ 10,00'`, `'+R$ 10,00'`, `'00050,00'`, `'-0,00'` (→ `0.00`).

**Rejeita:** `'1.234'`, `'1,234'`, `'1.234.567'`, `'1e3'`, `'NaN'`, `'--50'`, `'R$R$ 10'`, `'10 R$'`, `'R$1.181,47'`, `'R$ -10,00'`, `'- 50,00'`, `',50'`, `'10,'`, `'12.34,56'`, `'1.234,567.89'`, valor absoluto > `999.999.999.999,99`.

---

## Funcionalidades da UI

### Painel (Dashboard)
- Cards: patrimônio líquido, ativos, passivos, receitas/despesas/saldo do mês
- Gráfico de barras (canvas puro): receita × despesa dos últimos 6 meses
- Gráfico de pizza (canvas puro): despesas por categoria do mês corrente
- Lista das 8 transações mais recentes

### Transações
- Filtros: conta, mês (12 meses para trás), busca textual, mostrar excluídas
- Formulário modal: toggle Despesa/Receita, valor, data, conta, categoria (filtrada por tipo), beneficiário, memo, número, status
- Soft delete com restauração
- Status badges: Pendente, Compensada, Conciliada

### Contas
- Cards por tipo (7 tipos suportados, com ícone e cor)
- Saldo atual calculado via `Money::sum` (exato, não pela view)
- Saldo de abertura e data só são definidos na criação (spec §5.2: alterar `opening_date` não ajusta `opening_balance`)
- Exclusão protegida: só se não há transações ativas

### Categorias
- Árvore hierárquica de 2 níveis (parent_id self-FK)
- Subcategoria herda o `kind` do pai
- `ON DELETE RESTRICT`: não pode excluir com filhos ou em uso

### Beneficiários
- Entidade própria (não string solta)
- Categoria padrão opcional
- Exclusão com `ON DELETE SET NULL` em transactions

### Instituições
- Bancos, corretoras, fintechs
- Vinculadas às contas (1 instituição → N contas)

---

## Princípios de design (§3)

1. **Transferência é relação** via `transfer_group_id`. Não implementada no MVP.
2. **Cartão de crédito é `Account` tipo `credit_card`**, não categoria.
3. **Payee é entidade própria**, não string solta.
4. **Categoria é hierárquica** via `parent_id` self-FK.
5. **Split NÃO EXISTE NO MVP**. Tabela existe; nada a usa. `is_split = 0` sempre.
6. **Valores em `Money`**, arredondados com meia-unidade para longe de zero antes de persistir.
7. **Datas em `DateTimeImmutable` UTC**. Aplicação nunca lê o relógio diretamente — sempre `Dates::nowUtc()`.
8. **Soft delete em `transaction`**. Nunca apagar fisicamente.
9. **Nenhuma manipulação de valor ou data fora de `Money` e `Dates`**. Em `src/` (exceto testes), proibido: `(float)`, `floatval`, `round`, `number_format`, `bc*`, operadores aritméticos sobre valores monetários, `date()`, `gmdate()`, `strtotime()`, `time()`, `new DateTime`, `DateTimeImmutable::createFromFormat`, `->format(`.
10. **Moeda única (BRL)**.
11. **Sem framework**. Nenhum código de terceiros em runtime. Conexão com o banco só em `Database::connect`.

---

## Escopo do MVP (§10)

**Fase 1 — núcleo (sem UI):** completo (Money, Dates, Database, schema, testes).

**Fase 2 — UI mínima e serviços:** completo (Register, Accounts, painel de nova transação, dashboard).

**Fase 3 — Dashboard avançado:** parcial (cards + 2 gráficos + transações recentes).

**Fase 4 — extras (fora do MVP):** recorrência, splits, reclassificação, orçamento — não implementados.

---

## O que NÃO está no MVP (§11)

- Importação de formatos externos (QIF, OFX, CSV)
- Multi-moeda
- Splits operacionais
- Transferências criadas pela UI
- Recorrência / agendamento operacional
- Orçamento
- Migrations
- Framework, ORM ou biblioteca de terceiros em runtime
- Busca textual avançada
- Conciliação bancária automática
- Criptografia do banco
- Backup automático
- Sincronização
- Autenticação / multi-usuário
- Cálculo de juros

---

## Solução de problemas

### `PRAGMA foreign_keys não está ativo`
A extensão `pdo_sqlite` foi compilada sem suporte a foreign_keys. Recompile o PHP com `--enable-pdo-sqlite` e sqlite3 com foreign keys habilitado.

### `SQLite X.Y.Z é inferior ao mínimo exigido 3.35.0`
Atualize o SQLite do sistema. Em distribuições Linux: instale um PHP mais recente que traga um SQLite mais novo embutido.

### Erro 500 ao chamar `/api/*`
Verifique permissões de escrita em `data/`:
```bash
chmod -R 0775 data
```

### Testes falham com `Class "Moneyapp\Core\Money" not found`
Rode `composer install` para gerar o autoload. Sem composer, o `bootstrap.php` tem um fallback `spl_autoload_register` que também funciona.

---

## Licença

Proprietária. Uso interno.
