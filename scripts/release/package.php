<?php

declare(strict_types=1);

/*
 * Hilfsskript fuer scripts/build-release.sh (nur Kommandozeile):
 *   php package.php version-json <app-dir> <version>     -> version.json auf stdout
 *   php package.php zip <quell-ordner> <ziel.zip>         -> ZIP (Inhalt = Ordnerinhalt, sortiert, ohne .DS_Store)
 *   php package.php sha256 <datei>                        -> "<hash>  <dateiname>" (Format von sha256sum)
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

[$cmd, $a, $b] = array_pad(array_slice($argv, 1), 3, null);

switch ($cmd) {
    case 'version-json':
        $schema = 0;
        foreach (glob($a . '/migrations/*.php') ?: [] as $f) {
            if (preg_match('/^(\d{4})_/', basename($f), $m)) {
                $schema = max($schema, (int) $m[1]);
            }
        }
        echo json_encode(['version' => $b, 'schema' => $schema, 'built_at' => gmdate('Y-m-d\TH:i:s\Z')], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
        break;

    case 'zip':
        $src = rtrim((string) realpath($a), '/');
        $files = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $f) {
            $rel = substr($f->getPathname(), strlen($src) + 1);
            if (basename($rel) === '.DS_Store') {
                continue;
            }
            $files[$rel] = $f->isDir();
        }
        ksort($files, SORT_STRING);
        @unlink($b);
        $zip = new ZipArchive();
        if ($zip->open($b, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            fwrite(STDERR, "ZIP nicht anlegbar: $b\n");
            exit(1);
        }
        foreach ($files as $rel => $isDir) {
            $isDir ? $zip->addEmptyDir($rel) : $zip->addFile($src . '/' . $rel, $rel);
        }
        if (!$zip->close()) {
            fwrite(STDERR, "ZIP nicht schreibbar: $b\n");
            exit(1);
        }
        echo count($files), " Eintraege\n";
        break;

    case 'sha256':
        echo hash_file('sha256', $a), '  ', basename($a), "\n";
        break;

    default:
        fwrite(STDERR, "Unbekannter Befehl\n");
        exit(2);
}
