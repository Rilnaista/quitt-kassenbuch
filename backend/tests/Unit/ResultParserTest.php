<?php

declare(strict_types=1);

namespace Quitt\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Quitt\Recognition\ProviderException;
use Quitt\Recognition\ResultParser;

final class ResultParserTest extends TestCase
{
    public function testCleanJson(): void
    {
        $r = ResultParser::parse('{"date":"2026-10-05","positions":[{"item":"Milch","amount_cents":129},{"item":"Pfand","amount_cents":-25}],"total_cents":104}');
        self::assertSame('2026-10-05', $r['date']);
        self::assertSame([['item' => 'Milch', 'amount_cents' => 129], ['item' => 'Pfand', 'amount_cents' => -25]], $r['positions']);
        self::assertSame(104, $r['total_cents']);
    }

    public function testMarkdownFenceAndChatter(): void
    {
        $r = ResultParser::parse("Hier das Ergebnis:\n```json\n{\"date\":null,\"positions\":[],\"total_cents\":null}\n```\nViel Spaß");
        self::assertSame(['date' => null, 'positions' => [], 'total_cents' => null], $r);
        $r = ResultParser::parse('Gern: {"total_cents": 500} Ende');
        self::assertSame(500, $r['total_cents']);
    }

    public function testCommaDecimalsAndGermanDate(): void
    {
        $r = ResultParser::parse('{"datum":"05.10.2026","positions":[{"item":"Brot","amount_cents":"2,49"},{"name":"Käse","amount":"1.234,50 €"},{"item":"Obst","amount":3.5}],"total_cents":"1.240,49"}');
        self::assertSame('2026-10-05', $r['date']);
        self::assertSame([249, 123450, 350], array_column($r['positions'], 'amount_cents'));
        self::assertSame(124049, $r['total_cents']);
    }

    public function testCentVariants(): void
    {
        $r = ResultParser::parse('{"positions":[{"item":"a","amount_cents":249.0},{"item":"b","amount_cents":2.49},{"item":"c","amount_cents":"249"},{"item":"d","amount_cents":"-0,50"}]}');
        self::assertSame([249, 249, 249, -50], array_column($r['positions'], 'amount_cents'));
    }

    public function testInvalidEntriesDropped(): void
    {
        $r = ResultParser::parse('{"date":"31.02.2026","positions":[{"item":"","amount_cents":5},{"item":"x"},{"item":"y","amount_cents":0},"z",{"item":"ok","amount_cents":1}],"total_cents":0}');
        self::assertNull($r['date']);
        self::assertSame([['item' => 'ok', 'amount_cents' => 1]], $r['positions']);
        self::assertNull($r['total_cents']);
        self::assertSame('2026-03-04', ResultParser::parse('{"date":"4.3.26"}')['date']);
    }

    public function testUnreadable(): void
    {
        $this->expectException(ProviderException::class);
        ResultParser::parse('Das ist leider kein Beleg.');
    }
}
