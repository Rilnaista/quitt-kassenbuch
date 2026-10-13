<?php

declare(strict_types=1);

namespace Quitt\Service;

use Quitt\Db\Database;
use Quitt\Http\ApiException;
use Quitt\I18n\Lang;
use Quitt\Support\Clock;
use Quitt\Support\Time;

/**
 * Kontoauszug: Daten laden (Papierkorb zaehlt nicht), Saldo berechnen, Seiten bilden, PDF erzeugen.
 */
final class StatementService
{
    /** Zeichen je Textzeile (Spalte "Text", Monospace) */
    public const TEXT_WIDTH = 54;

    public function __construct(
        private readonly Database $db,
        private readonly SettingsService $settings,
        private readonly Lang $lang,
        private readonly Clock $clock,
        private readonly StatementPaginator $paginator,
        private readonly StatementPdf $pdf,
    ) {
    }

    /** Prueft from/to (YYYY-MM-DD, from <= to); wirft 422. */
    public function validateRange(mixed $from, mixed $to): void
    {
        $fields = [];
        foreach (['from' => $from, 'to' => $to] as $name => $value) {
            if (!Time::isDate($value)) {
                $fields[$name] = 'validation.date';
            }
        }
        if ($fields === [] && strcmp((string) $from, (string) $to) > 0) {
            $fields['to'] = 'validation.date_range';
        }
        if ($fields !== []) {
            throw ApiException::validation($fields);
        }
    }

    /**
     * @return array{group: string, from: string, to: string, created: string, opening: int, closing: int,
     *               pages: list<array{carry: int, rows: list<array<string, mixed>>, subtotal: int}>}
     */
    public function build(string $from, string $to): array
    {
        $this->validateRange($from, $to);
        $opening = (int) $this->db->scalar(
            "SELECT COALESCE(SUM(CASE type WHEN 'in' THEN amount_cents ELSE -amount_cents END), 0)
             FROM entries WHERE deleted_at IS NULL AND date < ?",
            [$from]
        );
        $rows = $this->db->all(
            'SELECT number, type, date, amount_cents, purpose, reason, category, item
             FROM entries WHERE deleted_at IS NULL AND date >= ? AND date <= ? ORDER BY date, number',
            [$from, $to]
        );
        $items = [];
        foreach ($rows as $row) {
            $in = $row['type'] === 'in';
            $lines = StatementPaginator::wrap(self::text($row), self::TEXT_WIDTH);
            $items[] = [
                'number' => (int) $row['number'],
                'date' => (string) $row['date'],
                'lines' => $lines,
                'out' => $in ? null : (int) $row['amount_cents'],
                'in' => $in ? (int) $row['amount_cents'] : null,
                'amount' => $in ? (int) $row['amount_cents'] : -(int) $row['amount_cents'],
                'height' => StatementPaginator::rowHeight(count($lines)),
            ];
        }
        $result = $this->paginator->paginate($opening, $items);
        return [
            'group' => (string) $this->settings->get('group_name'),
            'from' => $from,
            'to' => $to,
            'created' => $this->clock->now()->format('Y-m-d'),
            'opening' => $opening,
            'closing' => $result['closing'],
            'pages' => $result['pages'],
        ];
    }

    public function render(string $from, string $to): string
    {
        return $this->pdf->render($this->build($from, $to), $this->lang);
    }

    /** Einnahme = Verwendungszweck; Ausgabe = "Grund · Kategorie · Bezug". @param array<string, mixed> $row */
    public static function text(array $row): string
    {
        $parts = $row['type'] === 'in'
            ? [$row['purpose']]
            : [$row['reason'], $row['category'], $row['item']];
        $parts = array_filter(array_map(static fn ($p) => trim((string) $p), $parts), static fn ($p) => $p !== '');
        return implode(' · ', $parts);
    }

    public static function filename(string $from, string $to): string
    {
        return 'kontoauszug-' . $from . '_' . $to . '.pdf';
    }
}
