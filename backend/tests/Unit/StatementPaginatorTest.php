<?php

declare(strict_types=1);

namespace Quitt\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Quitt\Service\StatementPaginator;

final class StatementPaginatorTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function items(int $n): array
    {
        $items = [];
        for ($i = 1; $i <= $n; $i++) {
            $items[] = ['n' => $i, 'amount' => $i % 3 === 0 ? 1000 + $i : -(250 + $i), 'height' => StatementPaginator::rowHeight(1 + $i % 3)];
        }
        return $items;
    }

    public function testCarryEqualsPreviousSubtotalAndClosingIsSum(): void
    {
        $items = $this->items(120);
        $r = (new StatementPaginator())->paginate(5000, $items);
        self::assertGreaterThan(2, count($r['pages']));
        $prev = null;
        $count = 0;
        foreach ($r['pages'] as $i => $page) {
            self::assertSame($i === 0 ? 5000 : $prev, $page['carry']);
            $bal = $page['carry'];
            foreach ($page['rows'] as $row) {
                $bal += $row['amount'];
                self::assertSame($bal, $row['balance']);
                $count++;
            }
            self::assertSame($bal, $page['subtotal']);
            $prev = $page['subtotal'];
        }
        self::assertSame(120, $count);
        self::assertSame(5000 + array_sum(array_column($items, 'amount')), $r['closing']);
        self::assertSame($prev, $r['closing']);
    }

    public function testPageCapacityIsRespected(): void
    {
        $r = (new StatementPaginator())->paginate(0, $this->items(80));
        foreach ($r['pages'] as $i => $page) {
            $cap = $i === 0 ? StatementPaginator::FIRST_PAGE_CAPACITY : StatementPaginator::NEXT_PAGE_CAPACITY;
            self::assertLessThanOrEqual($cap + 1e-6, array_sum(array_column($page['rows'], 'height')));
        }
    }

    public function testEmptyStatementHasOnePage(): void
    {
        $r = (new StatementPaginator())->paginate(1234, []);
        self::assertCount(1, $r['pages']);
        self::assertSame(1234, $r['closing']);
        self::assertSame(1234, $r['pages'][0]['carry']);
    }

    public function testFormatCents(): void
    {
        self::assertSame('0,05', StatementPaginator::formatCents(5));
        self::assertSame('1.234,56', StatementPaginator::formatCents(123456));
        self::assertSame('-1.234.567,00', StatementPaginator::formatCents(-123456700));
    }

    public function testWrapKeepsUmlautsAndTruncates(): void
    {
        self::assertSame(['Süßigkeiten für Kinder'], StatementPaginator::wrap('Süßigkeiten für Kinder', 54));
        $lines = StatementPaginator::wrap(str_repeat('Überlänge ', 40), 20);
        self::assertCount(3, $lines);
        self::assertStringEndsWith('…', $lines[2]);
        foreach ($lines as $l) {
            self::assertLessThanOrEqual(20, mb_strlen($l));
        }
        $long = StatementPaginator::wrap(str_repeat('x', 50), 20);
        self::assertSame([str_repeat('x', 20), str_repeat('x', 20), str_repeat('x', 10)], $long);
    }
}
