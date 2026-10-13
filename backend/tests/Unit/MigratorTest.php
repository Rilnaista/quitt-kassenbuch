<?php

declare(strict_types=1);

namespace Quitt\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Quitt\Db\Database;
use Quitt\Db\Migrator;
use Quitt\Service\BackupService;
use Quitt\Tests\TestHelpers;

final class MigratorTest extends TestCase
{
    use TestHelpers;

    protected function setUp(): void
    {
        $this->bootContainer();
    }

    protected function tearDown(): void
    {
        $this->c->get(Database::class)->close();
        $this->cleanupDataDir();
    }

    public function testFreshMigrationCreatesSchemaWithoutBackup(): void
    {
        $applied = $this->c->get(Migrator::class)->migrate();
        self::assertContains('0001', $applied);
        $db = $this->c->get(Database::class);
        foreach (['entries', 'receipts', 'settings', 'auth_tokens', 'purged', 'login_attempts', 'meta', 'sequences', 'download_tickets', 'schema_migrations'] as $t) {
            self::assertTrue($db->tableExists($t), $t);
        }
        self::assertSame('wal', strtolower((string) $db->scalar('PRAGMA journal_mode')));
        self::assertSame(1, (int) $db->scalar('PRAGMA foreign_keys'));
        self::assertSame([], glob($this->dataDir . '/backups/auto-*'));
        self::assertSame([], $this->c->get(Migrator::class)->migrate(), 'zweiter Lauf ist leer');
    }

    public function testBacksUpBeforeMigratingExistingDatabase(): void
    {
        $migrDir = $this->dataDir . '/test-migrations';
        mkdir($migrDir);
        copy(self::appDir() . '/migrations/0001_initial.php', $migrDir . '/0001_initial.php');
        $db = $this->c->get(Database::class);
        $m = new Migrator($db, $migrDir, $this->c->get(BackupService::class), $this->c->get(\Psr\Log\LoggerInterface::class), $this->dataDir . '/.lock');
        $m->migrate();
        $db->run("INSERT INTO meta (key, value) VALUES ('probe', 'vorher')");

        file_put_contents($migrDir . '/0002_probe.php', "<?php return static function (PDO \$pdo): void { \$pdo->exec('CREATE TABLE probe (x INTEGER)'); };");
        self::assertSame(['0002'], $m->migrate());
        self::assertTrue($db->tableExists('probe'));

        $backups = glob($this->dataDir . '/backups/auto-*-before_migration.zip');
        self::assertCount(1, $backups);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($backups[0]));
        $dbCopy = $this->dataDir . '/extracted.sqlite';
        file_put_contents($dbCopy, $zip->getFromName('database.sqlite'));
        $copy = new \PDO('sqlite:' . $dbCopy);
        self::assertSame('vorher', $copy->query("SELECT value FROM meta WHERE key = 'probe'")->fetchColumn());
        self::assertFalse($copy->query("SELECT 1 FROM sqlite_master WHERE name = 'probe'")->fetchColumn(), 'Sicherung = Stand vor Migration');
    }

    public function testFailingMigrationRollsBack(): void
    {
        $migrDir = $this->dataDir . '/test-migrations';
        mkdir($migrDir);
        copy(self::appDir() . '/migrations/0001_initial.php', $migrDir . '/0001_initial.php');
        file_put_contents($migrDir . '/0002_broken.php', "<?php return static function (PDO \$pdo): void { \$pdo->exec('CREATE TABLE half (x INTEGER)'); \$pdo->exec('KAPUTT'); };");
        $db = $this->c->get(Database::class);
        $m = new Migrator($db, $migrDir, $this->c->get(BackupService::class), $this->c->get(\Psr\Log\LoggerInterface::class), $this->dataDir . '/.lock');
        try {
            $m->migrate();
            self::fail('Exception erwartet');
        } catch (\PDOException) {
        }
        self::assertSame(['0001'], $m->applied());
        self::assertFalse($db->tableExists('half'));
    }

    public function testBackupRetentionKeepsTen(): void
    {
        $this->migrate();
        $b = $this->c->get(BackupService::class);
        for ($i = 0; $i < 12; $i++) {
            $b->autoBackup(BackupService::REASON_MANUAL);
            $this->clock->advance(1);
        }
        self::assertCount(10, glob($this->dataDir . '/backups/auto-*'));
    }
}
