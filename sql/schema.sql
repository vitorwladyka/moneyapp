-- MoneyApp v2.0 — schema SQLite normativo (spec §8.2).
-- DECISÃO FECHADA: este arquivo É o DDL. Divergência é bug.
-- Verificado em SQLite 3.45.1: aplica 2× sem erro; CHECKs e view validados.

PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS institution (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL UNIQUE COLLATE NOCASE,
    notes TEXT,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS account (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL UNIQUE COLLATE NOCASE,
    type TEXT NOT NULL
        CHECK (type IN ('bank','credit_card','cash','investment','loan','asset','liability')),
    institution_id INTEGER REFERENCES institution(id) ON DELETE SET NULL,
    account_number TEXT,
    abbreviation TEXT,
    currency TEXT NOT NULL DEFAULT 'BRL',
    opening_balance NUMERIC(14,2) NOT NULL DEFAULT 0,
    opening_date TEXT NOT NULL
        CHECK (opening_date GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]'
               AND date(opening_date) IS NOT NULL
               AND date(opening_date) = opening_date),
    minimum_balance NUMERIC(14,2),
    interest_rate REAL,
    comment TEXT,
    is_favorite INTEGER NOT NULL DEFAULT 0 CHECK (is_favorite IN (0,1)),
    is_closed INTEGER NOT NULL DEFAULT 0 CHECK (is_closed IN (0,1)),
    include_in_budget INTEGER NOT NULL DEFAULT 1 CHECK (include_in_budget IN (0,1)),
    include_in_totals INTEGER NOT NULL DEFAULT 1 CHECK (include_in_totals IN (0,1)),
    display_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS category (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    parent_id INTEGER REFERENCES category(id) ON DELETE RESTRICT,
    kind TEXT NOT NULL CHECK (kind IN ('income','expense','transfer')),
    is_system INTEGER NOT NULL DEFAULT 0 CHECK (is_system IN (0,1)),
    display_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_category_root_name
    ON category(name COLLATE NOCASE) WHERE parent_id IS NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_category_child_name
    ON category(parent_id, name COLLATE NOCASE) WHERE parent_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS payee (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL UNIQUE COLLATE NOCASE,
    default_category_id INTEGER REFERENCES category(id) ON DELETE SET NULL,
    notes TEXT,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS "transaction" (
    id INTEGER PRIMARY KEY,
    account_id INTEGER NOT NULL REFERENCES account(id) ON DELETE RESTRICT,
    date TEXT NOT NULL
        CHECK (date GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]'
               AND date(date) IS NOT NULL
               AND date(date) = date),
    amount NUMERIC(14,2) NOT NULL,
    payee_id INTEGER REFERENCES payee(id) ON DELETE SET NULL,
    category_id INTEGER REFERENCES category(id) ON DELETE RESTRICT,
    memo TEXT,
    number TEXT,
    status TEXT NOT NULL DEFAULT 'none' CHECK (status IN ('none','cleared','reconciled')),
    transfer_group_id INTEGER,
    is_split INTEGER NOT NULL DEFAULT 0 CHECK (is_split IN (0,1)),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    deleted_at TEXT,
    CHECK (
        (transfer_group_id IS NULL AND category_id IS NOT NULL AND is_split = 0)
        OR (transfer_group_id IS NULL AND category_id IS NULL AND is_split = 1)
        OR (transfer_group_id IS NOT NULL AND category_id IS NULL AND is_split = 0)
    )
);

CREATE INDEX IF NOT EXISTS idx_transaction_account_date ON "transaction"(account_id, date, id);
CREATE INDEX IF NOT EXISTS idx_transaction_date ON "transaction"(date);
CREATE INDEX IF NOT EXISTS idx_transaction_payee ON "transaction"(payee_id);
CREATE INDEX IF NOT EXISTS idx_transaction_category ON "transaction"(category_id);
CREATE INDEX IF NOT EXISTS idx_transaction_transfer_group ON "transaction"(transfer_group_id);
CREATE INDEX IF NOT EXISTS idx_transaction_deleted_at ON "transaction"(deleted_at);

CREATE TABLE IF NOT EXISTS transaction_split (
    id INTEGER PRIMARY KEY,
    transaction_id INTEGER NOT NULL REFERENCES "transaction"(id) ON DELETE CASCADE,
    category_id INTEGER NOT NULL REFERENCES category(id) ON DELETE RESTRICT,
    amount NUMERIC(14,2) NOT NULL,
    memo TEXT,
    display_order INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS app_setting (
    key TEXT PRIMARY KEY,
    value TEXT
);

CREATE TABLE IF NOT EXISTS scheduled_transaction (
    id INTEGER PRIMARY KEY,
    account_id INTEGER NOT NULL REFERENCES account(id) ON DELETE CASCADE,
    payee_id INTEGER REFERENCES payee(id) ON DELETE SET NULL,
    category_id INTEGER REFERENCES category(id) ON DELETE SET NULL,
    amount NUMERIC(14,2) NOT NULL,
    memo TEXT,
    frequency TEXT NOT NULL
        CHECK (frequency IN ('daily','weekly','biweekly','monthly','bimonthly','quarterly','semiannual','annual','custom')),
    interval_count INTEGER NOT NULL DEFAULT 1,
    next_date TEXT NOT NULL
        CHECK (next_date GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]'
               AND date(next_date) IS NOT NULL
               AND date(next_date) = next_date),
    end_date TEXT
        CHECK (end_date GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]'
               AND date(end_date) IS NOT NULL
               AND date(end_date) = end_date),
    remaining_occurrences INTEGER,
    is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0,1)),
    created_at TEXT NOT NULL
);

-- View auxiliar de saldo (§5.3). Inclui contas abertas E fechadas.
-- Não filtra deleted_at no JOIN (feito abaixo). Não filtra por include_in_totals.
-- Não filtra t.date >= a.opening_date (invariante garantida pelo service).
DROP VIEW IF EXISTS account_balance;
CREATE VIEW account_balance AS
SELECT
    a.id, a.name, a.type, a.currency,
    a.opening_balance + COALESCE(SUM(t.amount), 0) AS balance,
    COUNT(t.id) AS transaction_count
FROM account a
LEFT JOIN "transaction" t
       ON t.account_id = a.id
      AND t.deleted_at IS NULL
GROUP BY a.id;
