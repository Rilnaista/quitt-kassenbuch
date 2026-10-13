<?php

declare(strict_types=1);

/** 0002 Optionale Notiz je Buchung (REQ-079, max. 100 Zeichen, Pruefung in SyncService). */
return static function (PDO $pdo): void {
    $pdo->exec('ALTER TABLE entries ADD COLUMN note TEXT NULL');
};
