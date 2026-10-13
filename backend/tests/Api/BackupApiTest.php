<?php

declare(strict_types=1);

namespace Quitt\Tests\Api;

use Quitt\Tests\ApiTestCase;

final class BackupApiTest extends ApiTestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->install();
        $this->token = $this->login();
    }

    private function sync(array $entries): void
    {
        $r = $this->request('POST', '/sync', ['entries' => $entries], $this->token);
        self::assertSame(200, $r->getStatusCode(), (string) $r->getBody());
    }

    private function entryIds(): array
    {
        $pull = $this->decode($this->request('GET', '/sync?since=0', null, $this->token));
        return array_values(array_map(fn ($e) => $e['id'], array_filter($pull['entries'], fn ($e) => $e['deleted_at'] === null)));
    }

    private function download(): string
    {
        $r = $this->request('GET', '/backup', null, $this->token);
        self::assertSame(200, $r->getStatusCode());
        self::assertSame('application/zip', $r->getHeaderLine('Content-Type'));
        self::assertMatchesRegularExpression('/quitt-sicherung-\d{4}-\d{2}-\d{2}\.zip/', $r->getHeaderLine('Content-Disposition'));
        return (string) $r->getBody();
    }

    private function zipOf(array $files): string
    {
        $path = $this->dataDir . '/crafted-' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        return (string) file_get_contents($path);
    }

    public function testBackupZipContainsManifestDbReceiptsSettings(): void
    {
        $rid = self::uuid(9);
        $this->multipart('PUT', '/receipts/' . $rid, self::jpeg(), $this->token);
        $this->sync([self::entry(1, ['receipt_id' => $rid])]);
        $this->c->get(\Quitt\Service\SettingsService::class)->set('ai_key', 'GEHEIM-KEY-123');

        $path = $this->dataDir . '/dl.zip';
        file_put_contents($path, $this->download());
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        self::assertSame('quitt-backup/1', $manifest['format']);
        self::assertSame(2, $manifest['schema_version']);
        self::assertSame(1, $manifest['entries']);
        self::assertSame(1, $manifest['receipts']);
        self::assertNotSame('', $manifest['app_version']);
        self::assertNotFalse($zip->locateName('database.sqlite'));
        self::assertNotFalse($zip->locateName('receipts/' . $rid));
        self::assertSame('GEHEIM-KEY-123', json_decode($zip->getFromName('app-settings.json'), true)['ai_key']);
        // Anmeldungen gehoeren nicht in die Sicherung
        $db = $this->dataDir . '/x.sqlite';
        file_put_contents($db, $zip->getFromName('database.sqlite'));
        self::assertSame(0, (int) (new \PDO('sqlite:' . $db))->query('SELECT COUNT(*) FROM auth_tokens')->fetchColumn());
    }

    public function testBackupNeedsToken(): void
    {
        $this->assertError($this->request('GET', '/backup'), 401, 'unauthorized');
        $this->setReadWithoutPin(true);
        $this->assertError($this->request('GET', '/backup'), 401, 'unauthorized');
    }

    public function testBackupViaDownloadTicket(): void
    {
        $url = $this->decode($this->request('POST', '/downloads', ['target' => 'backup'], $this->token))['url'];
        self::assertMatchesRegularExpression('#^api/v1/backup\?ticket=[0-9a-f]{48}$#', $url);
        $r = $this->request('GET', substr($url, strlen('api/v1')));
        self::assertSame('application/zip', $r->getHeaderLine('Content-Type'));
        $this->assertError($this->request('GET', substr($url, strlen('api/v1'))), 401, 'unauthorized');
    }

    public function testRestoreRoundTrip(): void
    {
        $rid = self::uuid(9);
        $this->multipart('PUT', '/receipts/' . $rid, self::jpeg('A'), $this->token);
        $this->sync([self::entry(1, ['receipt_id' => $rid]), self::entry(2)]);
        $zip = $this->download();

        // Buchung loeschen (endgueltig) + Beleg neu
        $this->sync([self::entry(2, ['deleted_at' => '2026-10-05T00:00:00Z', 'updated_at' => '2026-10-05T00:00:00Z'])]);
        $this->request('POST', '/trash/empty', null, $this->token);
        self::assertSame([self::uuid(1)], $this->entryIds());

        $r = $this->multipart('POST', '/restore', $zip, $this->token);
        self::assertSame(200, $r->getStatusCode(), (string) $r->getBody());
        self::assertSame(['ok' => true, 'entries' => 2, 'receipts' => 1], $this->decode($r));
        self::assertEqualsCanonicalizing([self::uuid(1), self::uuid(2)], $this->entryIds());

        // Beleg-Datei da, Gerät bleibt angemeldet (gleicher Token weiter gültig)
        $f = $this->request('GET', '/receipts/' . $rid . '/file', null, $this->token);
        self::assertSame(200, $f->getStatusCode());
        self::assertSame(self::jpeg('A'), (string) $f->getBody());
        // Sicherung vor Restore wurde angelegt
        $list = $this->decode($this->request('GET', '/backups', null, $this->token));
        self::assertContains('before_restore', array_column($list, 'reason'));
        self::assertSame([], glob($this->dataDir . '/.tmp-*'), 'keine Temp-Reste');
    }

    public function testBackupsListAndRestoreByName(): void
    {
        $this->sync([self::entry(1)]);
        $this->c->get(\Quitt\Service\BackupService::class)->autoBackup('before_update');
        $this->sync([self::entry(2)]);
        $list = $this->decode($this->request('GET', '/backups', null, $this->token));
        self::assertCount(1, $list);
        self::assertSame('before_update', $list[0]['reason']);
        self::assertSame('2026-10-13T08:00:00.000Z', $list[0]['created_at']);
        self::assertGreaterThan(0, $list[0]['size']);

        $r = $this->request('POST', '/backups/' . $list[0]['name'] . '/restore', null, $this->token);
        self::assertSame(200, $r->getStatusCode(), (string) $r->getBody());
        self::assertSame(1, $this->decode($r)['entries']);
        self::assertSame([self::uuid(1)], $this->entryIds());
    }

    public function testRestoreByNameValidatesName(): void
    {
        foreach (['..%2F..%2Fquitt.sqlite', 'auto-x.zip', 'auto-20260101-000000-manual.zip', '..'] as $name) {
            $this->assertError($this->request('POST', '/backups/' . $name . '/restore', null, $this->token), 404, 'not_found');
        }
        $this->assertError($this->request('POST', '/backups/auto-20260101-000000-manual.zip/restore'), 401, 'unauthorized');
    }

    public function testRotationKeepsTenAutomatic(): void
    {
        $b = $this->c->get(\Quitt\Service\BackupService::class);
        for ($i = 0; $i < 12; $i++) {
            $b->autoBackup('manual');
            $this->clock->advance(1);
        }
        self::assertCount(10, $this->decode($this->request('GET', '/backups', null, $this->token)));
    }

    public function testZipSlipIsRejectedAndNothingWritten(): void
    {
        $manifest = json_encode(['format' => 'quitt-backup/1', 'schema_version' => 1]);
        $db = (string) file_get_contents($this->dataDir . '/quitt.sqlite');
        foreach (['../evil.txt', 'receipts/../../evil.txt', '/abs/evil.txt', 'receipts/..\\..\\evil.txt'] as $bad) {
            $zip = $this->zipOf(['manifest.json' => $manifest, 'database.sqlite' => $db, $bad => 'pwn']);
            $this->assertError($this->multipart('POST', '/restore', $zip, $this->token), 422, 'validation_failed');
        }
        self::assertFileDoesNotExist(dirname($this->dataDir) . '/evil.txt');
        self::assertFileDoesNotExist($this->dataDir . '/evil.txt');
        self::assertFileDoesNotExist('/abs/evil.txt');
        self::assertSame([], glob($this->dataDir . '/.tmp-*'));
    }

    public function testInvalidUploads(): void
    {
        $this->sync([self::entry(1)]);
        $this->assertError($this->multipart('POST', '/restore', 'kein zip', $this->token), 422, 'validation_failed');
        $this->assertError($this->multipart('POST', '/restore', $this->zipOf(['manifest.json' => '{}', 'database.sqlite' => 'x']), $this->token), 422, 'validation_failed');
        $this->assertError($this->multipart('POST', '/restore', 'x', $this->token, 'andere'), 422, 'validation_failed');
        $this->assertError($this->multipart('POST', '/restore', 'x', null), 401, 'unauthorized');
        // Kaputte DB im gueltigen Rahmen
        $zip = $this->zipOf(['manifest.json' => json_encode(['format' => 'quitt-backup/1', 'schema_version' => 1]), 'database.sqlite' => 'KEINE DB']);
        $this->assertError($this->multipart('POST', '/restore', $zip, $this->token), 422, 'validation_failed');
        self::assertSame([self::uuid(1)], $this->entryIds(), 'alter Stand bleibt');
    }

    public function testNewerBackupIsRejected(): void
    {
        $this->sync([self::entry(1)]);
        $db = (string) file_get_contents($this->dataDir . '/quitt.sqlite');
        $zip = $this->zipOf(['manifest.json' => json_encode(['format' => 'quitt-backup/1', 'schema_version' => 99]), 'database.sqlite' => $db]);
        $this->assertError($this->multipart('POST', '/restore', $zip, $this->token), 422, 'backup_too_new');
        self::assertSame([self::uuid(1)], $this->entryIds());
    }

    public function testOlderBackupIsMigrated(): void
    {
        // Eigene Migrationsquelle mit 0003; Sicherung enthaelt nur bis 0002.
        $migDir = $this->dataDir . '/mig';
        mkdir($migDir);
        copy(self::appDir() . '/migrations/0001_initial.php', $migDir . '/0001_initial.php');
        copy(self::appDir() . '/migrations/0002_entry_note.php', $migDir . '/0002_entry_note.php');
        file_put_contents($migDir . '/0003_probe.php', "<?php return static function (PDO \$pdo): void { \$pdo->exec('CREATE TABLE probe (x INTEGER)'); };");
        $db = $this->c->get(\Quitt\Db\Database::class);
        $backup = $this->c->get(\Quitt\Service\BackupService::class);
        $migrator = new \Quitt\Db\Migrator($db, $migDir, $backup, $this->c->get(\Psr\Log\LoggerInterface::class), $this->dataDir . '/.l2');
        $svc = new \Quitt\Service\RestoreService(
            $this->c->get(\Quitt\App\Config::class), $db, $migrator, $backup, $this->c->get(\Psr\Log\LoggerInterface::class)
        );
        $zipPath = $this->dataDir . '/old.zip';
        $backup->createArchive($zipPath);
        $res = $svc->restoreFile($zipPath);
        self::assertTrue($res['ok']);
        self::assertTrue($db->tableExists('probe'));
        self::assertSame(['0001', '0002', '0003'], $migrator->applied());
    }

    public function testFailedMigrationKeepsOldState(): void
    {
        $this->sync([self::entry(1)]);
        $migDir = $this->dataDir . '/mig';
        mkdir($migDir);
        copy(self::appDir() . '/migrations/0001_initial.php', $migDir . '/0001_initial.php');
        copy(self::appDir() . '/migrations/0002_entry_note.php', $migDir . '/0002_entry_note.php');
        file_put_contents($migDir . '/0003_broken.php', "<?php return static function (PDO \$pdo): void { \$pdo->exec('KAPUTT'); };");
        $db = $this->c->get(\Quitt\Db\Database::class);
        $backup = $this->c->get(\Quitt\Service\BackupService::class);
        $log = $this->c->get(\Psr\Log\LoggerInterface::class);
        $svc = new \Quitt\Service\RestoreService(
            $this->c->get(\Quitt\App\Config::class), $db, new \Quitt\Db\Migrator($db, $migDir, $backup, $log, $this->dataDir . '/.l2'), $backup, $log
        );
        $zipPath = $this->dataDir . '/old.zip';
        $backup->createArchive($zipPath);
        $this->sync([self::entry(2)]);
        try {
            $svc->restoreFile($zipPath);
            self::fail('Exception erwartet');
        } catch (\PDOException) {
        }
        self::assertEqualsCanonicalizing([self::uuid(1), self::uuid(2)], $this->entryIds(), 'alter Stand wiederhergestellt');
    }

    public function testSchema1BackupRestoresAndMigratesToNote(): void
    {
        $migDir = $this->dataDir . '/mig';
        mkdir($migDir);
        copy(self::appDir() . '/migrations/0001_initial.php', $migDir . '/0001_initial.php');
        $db = $this->c->get(\Quitt\Db\Database::class);
        $backup = $this->c->get(\Quitt\Service\BackupService::class);
        $log = $this->c->get(\Psr\Log\LoggerInterface::class);
        // Stand "Schema 1": Spalte note entfernen und 0002 austragen
        $this->sync([self::entry(1)]);
        $db->pdo()->exec('ALTER TABLE entries DROP COLUMN note');
        $db->pdo()->exec("DELETE FROM schema_migrations WHERE version = '0002'");
        $zipPath = $this->dataDir . '/schema1.zip';
        $backup->createArchive($zipPath);
        $z = new \ZipArchive();
        $z->open($zipPath);
        self::assertSame(1, json_decode((string) $z->getFromName('manifest.json'), true)['schema_version']);
        $z->close();

        $svc = new \Quitt\Service\RestoreService(
            $this->c->get(\Quitt\App\Config::class), $db, $this->c->get(\Quitt\Db\Migrator::class), $backup, $log
        );
        $res = $svc->restoreFile($zipPath);
        self::assertTrue($res['ok']);
        self::assertSame(1, $res['entries']);
        self::assertContains('0002', $this->c->get(\Quitt\Db\Migrator::class)->applied());
        $this->sync([self::entry(2, ['note' => 'Hallo'])]);
        $e = $this->decode($this->request('GET', '/sync?since=0', null, $this->token))['entries'];
        self::assertEqualsCanonicalizing([null, 'Hallo'], array_column($e, 'note'));
    }
}
