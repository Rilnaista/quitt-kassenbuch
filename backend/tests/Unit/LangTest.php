<?php

declare(strict_types=1);

namespace Quitt\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quitt\Tests\Fixtures;

/**
 * Nutzersichtbare Texte: echte Umlaute/ß statt ae/oe/ue/ss-Ersatz, UTF-8 ohne BOM,
 * und jeder im Code verwendete Meldungsschluessel existiert.
 */
final class LangTest extends TestCase
{
    /** Woerter, in denen ae/oe/ue korrekt ist (klein geschrieben). Bei Bedarf ergaenzen. */
    private const ALLOWED = [
        'neue', 'neuen', 'neuer', 'neues', 'erneut', 'aktuell', 'aktuelle', 'aktuellen', 'quelle', 'quellen',
        'steuer', 'dauer', 'bauer', 'mauer', 'feuer', 'abenteuer', 'zuerst', 'manuell', 'manuelle', 'individuell',
        'eventuell', 'virtuell', 'israel', 'michael', 'poet', 'koexistenz', 'queue', 'blues', 'true', 'value', 'values',
    ];

    /** typische ß-Ersatzschreibungen */
    private const SS_STEMS = ['gross', 'strasse', 'gruss', 'heiss', 'schliess', 'fuss', 'gemaess', 'ausser', 'massnahme', 'weiss'];

    public static function langFiles(): array
    {
        $out = [];
        foreach (glob(Fixtures::appDir() . '/lang/*.php') as $f) {
            $out[basename($f)] = [$f];
        }
        return $out;
    }

    #[DataProvider('langFiles')]
    public function testNoUmlautSubstitutes(string $file): void
    {
        $messages = require $file;
        self::assertIsArray($messages);
        $bad = [];
        foreach ($messages as $key => $text) {
            self::assertIsString($text, $key);
            preg_match_all('/[\p{L}]+/u', $text, $m);
            foreach ($m[0] as $word) {
                $w = mb_strtolower($word);
                if (preg_match('/ae|oe|ue/', $w) && !in_array($w, self::ALLOWED, true)) {
                    $bad[] = "$key: $word";
                }
                foreach (self::SS_STEMS as $stem) {
                    if (str_contains($w, $stem)) {
                        $bad[] = "$key: $word (ß?)";
                    }
                }
            }
        }
        self::assertSame([], $bad, 'Ersatzschreibung statt Umlaut/ß in ' . basename($file));
    }

    #[DataProvider('langFiles')]
    public function testUtf8WithoutBom(string $file): void
    {
        $raw = file_get_contents($file);
        self::assertFalse(str_starts_with($raw, "\xEF\xBB\xBF"), 'BOM gefunden');
        self::assertTrue(mb_check_encoding($raw, 'UTF-8'));
    }

    public function testDetectorWorks(): void
    {
        // Selbsttest des Pruefers: Ersatzschreibung muss auffallen
        $w = 'pruefen';
        self::assertMatchesRegularExpression('/ae|oe|ue/', $w);
        self::assertNotContains($w, self::ALLOWED);
    }

    public function testAllUsedKeysExist(): void
    {
        $messages = require Fixtures::appDir() . '/lang/de.php';
        $code = '';
        foreach (['src', 'modules', 'bin'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(Fixtures::appDir() . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->getExtension() === 'php') {
                    $code .= file_get_contents($f->getPathname());
                }
            }
        }
        preg_match_all("/'((?:validation|settings)\\.[a-z_]+)'/", $code, $m);
        $keys = array_unique($m[1]);
        preg_match_all("/new ApiException\\(\\d+, '([a-z_]+)'/", $code, $m2);
        preg_match_all("/'code' => '([a-z_]+)'/", $code, $m3);
        foreach (array_merge($m2[1], $m3[1], ['unauthorized', 'pin_required', 'validation_failed', 'not_found', 'rate_limited', 'not_installed', 'bad_request', 'internal_error', 'method_not_allowed', 'invalid_json']) as $c) {
            $keys[] = 'error.' . $c;
        }
        $missing = array_values(array_diff(array_unique($keys), array_keys($messages)));
        self::assertSame([], $missing, 'Fehlende Schluessel in lang/de.php');
    }
}
