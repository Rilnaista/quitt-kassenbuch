<?php

declare(strict_types=1);

namespace Quitt\App;

/**
 * Legt den Datenordner samt Unterordnern an und sperrt ihn gegen Webzugriff
 * (.htaccess fuer Apache 2.2/2.4 + index.php-Waechter in jedem Ordner).
 */
final class DataDir
{
    public const SUBDIRS = ['receipts', 'logs', 'backups'];

    private const HTACCESS = <<<'HT'
# Quitt-Datenordner: niemals oeffentlich ausliefern
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>
Options -Indexes

HT;

    private const GUARD = "<?php\nhttp_response_code(403);\nexit;\n";

    public static function ensure(string $dataDir): void
    {
        foreach (array_merge([''], self::SUBDIRS) as $sub) {
            $dir = $sub === '' ? $dataDir : $dataDir . '/' . $sub;
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('Datenordner nicht anlegbar: ' . $dir);
            }
            if (!is_file($dir . '/index.php')) {
                @file_put_contents($dir . '/index.php', self::GUARD);
            }
        }
        if (!is_file($dataDir . '/.htaccess')) {
            @file_put_contents($dataDir . '/.htaccess', self::HTACCESS);
        }
    }
}
