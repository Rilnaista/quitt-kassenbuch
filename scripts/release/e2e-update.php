<?php

declare(strict_types=1);

/*
 * Nur Tests: spielt in einer echten Release-Installation ein Update ein (UpdateService der installierten Fassung,
 * GitHub durch eine Attrappe ersetzt). Das neue Paket entsteht aus dem vorhandenen durch Umbenennen der Version
 * plus einer Test-Migration (bzw. einer fehlschlagenden Migration mit "fail").
 *   php e2e-update.php <installationsordner> <paket.zip> <neue-version> [fail]
 * Ausgabe: JSON {ok, from, to} oder {error, message_key}; Exit 0/1.
 */

if (PHP_SAPI !== 'cli' || $argc < 4) {
    fwrite(STDERR, "Aufruf: php e2e-update.php <ordner> <paket.zip> <version> [fail]\n");
    exit(2);
}
[$dir, $orig, $version] = [rtrim($argv[1], '/'), $argv[2], $argv[3]];
$fail = ($argv[4] ?? '') === 'fail';

// 1. Neues Paket bauen
$pkg = sys_get_temp_dir() . '/quitt-' . $version . '-' . bin2hex(random_bytes(4)) . '.zip';
$in = new ZipArchive();
$in->open($orig);
$out = new ZipArchive();
$out->open($pkg, ZipArchive::CREATE | ZipArchive::OVERWRITE);
for ($i = 0; $i < $in->numFiles; $i++) {
    $name = (string) $in->getNameIndex($i);
    if (in_array($name, ['version.json', 'app/VERSION'], true)) {
        continue;
    }
    str_ends_with($name, '/') ? $out->addEmptyDir(rtrim($name, '/')) : $out->addFromString($name, (string) $in->getFromIndex($i));
}
$in->close();
$out->addFromString('version.json', json_encode(['version' => $version, 'schema' => 900]));
$out->addFromString('app/VERSION', $version . "\n");
$out->addFromString('app/migrations/0900_e2e_update.php', "<?php\nreturn static function (PDO \$pdo): void { \$pdo->exec('CREATE TABLE e2e_update (id INTEGER)'); };\n");
if ($fail) {
    $out->addFromString('app/migrations/0901_e2e_fail.php', "<?php\nreturn static function (PDO \$pdo): void { \$pdo->exec('CREATE TABLE e2e_half (id INTEGER)'); throw new RuntimeException('E2E: absichtlicher Fehler'); };\n");
}
$out->close();

// 2. Installierte Fassung laden und Update einspielen
require $dir . '/app/vendor/autoload.php';

$http = new class ($pkg, $version) implements \Quitt\Update\HttpClient {
    public function __construct(private string $pkg, private string $version)
    {
    }

    public function get(string $url, array $headers, int $timeoutSeconds): array
    {
        if (str_contains($url, 'api.github.com')) {
            $base = 'https://e2e.invalid/quitt-' . $this->version . '.zip';
            return ['status' => 200, 'body' => json_encode([[
                'tag_name' => 'v' . $this->version, 'prerelease' => true, 'body' => 'E2E', 'published_at' => gmdate('c'),
                'assets' => [['name' => 'quitt-' . $this->version . '.zip', 'browser_download_url' => $base],
                    ['name' => 'quitt-' . $this->version . '.zip.sha256', 'browser_download_url' => $base . '.sha256']],
            ]])];
        }
        return ['status' => 200, 'body' => hash_file('sha256', $this->pkg) . "  quitt.zip\n"];
    }

    public function download(string $url, array $headers, string $target, int $timeoutSeconds): int
    {
        copy($this->pkg, $target);
        return 200;
    }
};

$config = \Quitt\App\Config::fromEnvironment($dir . '/app', $dir . '/data');
$c = \Quitt\App\Bootstrap::container($config);
\Quitt\App\Bootstrap::app($c, '');
$c->instance(\Quitt\Update\HttpClient::class, $http);
try {
    $r = $c->get(\Quitt\Service\UpdateService::class)->apply();
    echo json_encode($r), "\n";
    $code = 0;
} catch (\Quitt\Http\ApiException $e) {
    echo json_encode(['error' => $e->errorCode, 'message_key' => $e->messageKey]), "\n";
    $code = 1;
} finally {
    @unlink($pkg);
}
exit($code);
