<?php

declare(strict_types=1);

/**
 * 0001 Grundschema Quitt.
 * Zeitstempel als ISO-8601-UTC-Text (ms, "Z"), damit Textvergleich = Zeitvergleich (LWW).
 */
return static function (PDO $pdo): void {
    $pdo->exec(<<<'SQL'
CREATE TABLE meta (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

CREATE TABLE sequences (
    name  TEXT PRIMARY KEY,
    value INTEGER NOT NULL
);
INSERT INTO sequences (name, value) VALUES ('version', 0), ('entry_number', 0);

CREATE TABLE receipts (
    id         TEXT PRIMARY KEY,
    mime       TEXT NOT NULL CHECK (mime IN ('image/jpeg', 'image/png', 'image/webp')),
    size       INTEGER NOT NULL,
    sha256     TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    deleted_at TEXT NULL,
    version    INTEGER NOT NULL
);
CREATE INDEX receipts_version ON receipts (version);

CREATE TABLE entries (
    id           TEXT PRIMARY KEY,
    number       INTEGER NOT NULL UNIQUE,
    type         TEXT NOT NULL CHECK (type IN ('in', 'out')),
    date         TEXT NOT NULL,
    amount_cents INTEGER NOT NULL CHECK (amount_cents > 0),
    purpose      TEXT NULL,
    reason       TEXT NULL,
    category     TEXT NULL,
    item         TEXT NULL,
    receipt_id   TEXT NULL REFERENCES receipts (id),
    created_at   TEXT NOT NULL,
    updated_at   TEXT NOT NULL,
    deleted_at   TEXT NULL,
    version      INTEGER NOT NULL
);
CREATE INDEX entries_version ON entries (version);
CREATE INDEX entries_receipt ON entries (receipt_id);
CREATE INDEX entries_deleted ON entries (deleted_at);

-- Tombstones endgueltig geloeschter Datensaetze (fuer inkrementellen Sync)
CREATE TABLE purged (
    kind      TEXT NOT NULL CHECK (kind IN ('entry', 'receipt')),
    id        TEXT NOT NULL,
    version   INTEGER NOT NULL,
    purged_at TEXT NOT NULL,
    PRIMARY KEY (kind, id)
);
CREATE INDEX purged_version ON purged (version);

-- Werte JSON-kodiert
CREATE TABLE settings (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

-- nur SHA-256 des Tokens
CREATE TABLE auth_tokens (
    token_hash   TEXT PRIMARY KEY,
    created_at   TEXT NOT NULL,
    last_used_at TEXT NOT NULL,
    expires_at   TEXT NOT NULL
);

-- Fehlversuchszaehler pro Installation und Zweck (scope: login, reset)
CREATE TABLE login_attempts (
    scope        TEXT PRIMARY KEY,
    failures     INTEGER NOT NULL DEFAULT 0,
    locked_until INTEGER NOT NULL DEFAULT 0,
    last_failure INTEGER NOT NULL DEFAULT 0
);

-- Einmal-Tickets fuer Downloads (nur SHA-256)
CREATE TABLE download_tickets (
    ticket_hash TEXT PRIMARY KEY,
    target      TEXT NOT NULL,
    path        TEXT NOT NULL,
    query       TEXT NOT NULL,
    expires_at  INTEGER NOT NULL,
    used_at     INTEGER NULL
);
SQL);
};
