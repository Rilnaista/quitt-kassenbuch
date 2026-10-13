<?php

declare(strict_types=1);

namespace Quitt\Service;

use Quitt\App\Config;
use Quitt\I18n\Lang;

/**
 * Zeichnet das Kontoauszug-Modell (StatementService::build) mit tFPDF (UTF-8, JetBrains Mono, OFL).
 * Layout A4 hoch, Bankstil. Die Seitenkapazitaeten stehen in StatementPaginator (mit diesem Layout
 * abgestimmt: Kopf Seite 1 bis y=53, ab Seite 2 bis y=35, Fuss ab y=269).
 *
 * Die tFPDF-Schriftmetrik-Zwischendatei wird im Datenordner (cache/pdf-fonts-<hash>/unifont) abgelegt,
 * nicht im Quellbaum/vendor.
 */
final class StatementPdf
{
    private const FONT = 'JetBrainsMono';
    private const SIZE = 8.0;
    private const CHAR = 1.6934;       // 0.6 em bei 8 pt in mm
    private const TOP = 14.0;
    private const FOOT_Y = 269.0;
    private const COLS = ['nr' => 5, 'date' => 10, 'text' => StatementService::TEXT_WIDTH, 'out' => 10, 'in' => 10, 'bal' => 11];
    private const GAP = 1;

    public function __construct(private readonly Config $config)
    {
    }

    /** @param array<string, mixed> $m Modell aus StatementService::build() */
    public function render(array $m, Lang $lang): string
    {
        $this->bootFonts();
        $pdf = new \tFPDF('P', 'mm', 'A4');
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->AddFont(self::FONT, '', 'JetBrainsMono-Regular.ttf', true);
        $pdf->AddFont(self::FONT, 'B', 'JetBrainsMono-Bold.ttf', true);
        $title = $lang->t('statement.title') . ' ' . $m['group'];
        $pdf->SetTitle($title, true);
        $pdf->SetAuthor('Quitt', true);
        $pdf->SetCreator('Quitt', true);

        $x = $this->x();
        $pageCount = count($m['pages']);
        foreach ($m['pages'] as $i => $page) {
            $pdf->AddPage();
            $first = $i === 0;
            $y = self::TOP;
            if ($first) {
                $pdf->SetFont(self::FONT, 'B', 14);
                $pdf->SetXY($x, $y);
                $pdf->Cell(0, 8, $lang->t('statement.title'));
                $y += 8;
                $pdf->SetFont(self::FONT, 'B', 10);
                $pdf->SetXY($x, $y);
                $pdf->Cell(0, 5, (string) $m['group']);
                $y += 5;
                $pdf->SetFont(self::FONT, '', 9);
                $pdf->SetXY($x, $y);
                $pdf->Cell(0, 5, $lang->t('statement.period') . ': ' . self::date($m['from']) . ' – ' . self::date($m['to']));
                $y += 5;
                $pdf->SetXY($x, $y);
                $pdf->Cell(0, 5, $lang->t('statement.created') . ': ' . self::date($m['created']));
                $y += 5 + 4;
            } else {
                $pdf->SetFont(self::FONT, 'B', 9);
                $pdf->SetXY($x, $y);
                $pdf->Cell(0, 6, $lang->t('statement.title') . ' · ' . $m['group'] . ' · ' . self::date($m['from']) . ' – ' . self::date($m['to']));
                $y += 6 + 3;
            }
            $y = $this->columnHead($pdf, $lang, $y);
            $pdf->SetFont(self::FONT, '', self::SIZE);
            $y = $this->balanceRow($pdf, $y, $first ? $lang->t('statement.opening') : $lang->t('statement.carry'), $page['carry'], $first);
            $y += 1;
            foreach ($page['rows'] as $row) {
                $y = $this->entryRow($pdf, $y, $row);
            }
            // Fuss: Zwischensaldo bzw. Endsaldo, Seitenzahl
            $last = $i === $pageCount - 1;
            $pdf->SetDrawColor(0, 0, 0);
            $pdf->SetLineWidth(0.3);
            $pdf->Line($x, self::FOOT_Y, $x + $this->width(), self::FOOT_Y);
            $this->balanceRow($pdf, self::FOOT_Y + 1.5, $lang->t($last ? 'statement.closing' : 'statement.subtotal'), $last ? $m['closing'] : $page['subtotal'], true);
            $pdf->SetFont(self::FONT, '', 7);
            $pdf->SetXY($x, self::FOOT_Y + 9);
            $pdf->Cell($this->width(), 4, $lang->t('statement.page', ['page' => $i + 1, 'pages' => $pageCount]), 0, 0, 'R');
        }
        return $pdf->Output('S');
    }

    private function columnHead(\tFPDF $pdf, Lang $lang, float $y): float
    {
        $pdf->SetFont(self::FONT, 'B', self::SIZE);
        $labels = [
            'nr' => $lang->t('statement.col.number'), 'date' => $lang->t('statement.col.date'),
            'text' => $lang->t('statement.col.text'), 'out' => $lang->t('statement.col.out'),
            'in' => $lang->t('statement.col.in'), 'bal' => $lang->t('statement.col.balance'),
        ];
        foreach ($labels as $key => $label) {
            $right = in_array($key, ['out', 'in', 'bal'], true);
            $label = mb_substr($label, 0, self::COLS[$key]);
            $pdf->SetXY($this->colX($key), $y);
            $pdf->Cell($this->colW($key), 5, $label, 0, 0, $right ? 'R' : 'L');
        }
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetLineWidth(0.3);
        $pdf->Line($this->x(), $y + 5.4, $this->x() + $this->width(), $y + 5.4);
        return $y + 6;
    }

    private function balanceRow(\tFPDF $pdf, float $y, string $label, int $cents, bool $bold): float
    {
        $pdf->SetFont(self::FONT, $bold ? 'B' : '', self::SIZE);
        $pdf->SetXY($this->colX('text'), $y);
        $pdf->Cell($this->colW('text'), StatementPaginator::LINE_HEIGHT, $label);
        $pdf->SetXY($this->colX('bal'), $y);
        $pdf->Cell($this->colW('bal'), StatementPaginator::LINE_HEIGHT, StatementPaginator::formatCents($cents), 0, 0, 'R');
        return $y + 5.2;
    }

    /** @param array<string, mixed> $row */
    private function entryRow(\tFPDF $pdf, float $y, array $row): float
    {
        $pdf->SetFont(self::FONT, '', self::SIZE);
        $h = StatementPaginator::LINE_HEIGHT;
        $cells = [
            'nr' => [(string) $row['number'], 'R'],
            'date' => [self::date($row['date']), 'L'],
            'out' => [$row['out'] === null ? '' : StatementPaginator::formatCents($row['out']), 'R'],
            'in' => [$row['in'] === null ? '' : StatementPaginator::formatCents($row['in']), 'R'],
            'bal' => [StatementPaginator::formatCents($row['balance']), 'R'],
        ];
        foreach ($cells as $key => [$text, $align]) {
            $pdf->SetXY($this->colX($key), $y);
            $pdf->Cell($this->colW($key), $h, $text, 0, 0, $align);
        }
        foreach ($row['lines'] as $n => $line) {
            $pdf->SetXY($this->colX('text'), $y + $n * $h);
            $pdf->Cell($this->colW('text'), $h, $line);
        }
        $end = $y + count($row['lines']) * $h + StatementPaginator::ROW_GAP;
        $pdf->SetDrawColor(200, 200, 200);
        $pdf->SetLineWidth(0.1);
        $pdf->Line($this->x(), $end - 0.4, $this->x() + $this->width(), $end - 0.4);
        return $end;
    }

    private function width(): float
    {
        return (array_sum(self::COLS) + self::GAP * (count(self::COLS) - 1)) * self::CHAR;
    }

    private function x(): float
    {
        return (210.0 - $this->width()) / 2;
    }

    private function colX(string $key): float
    {
        $x = $this->x();
        foreach (self::COLS as $k => $w) {
            if ($k === $key) {
                return $x;
            }
            $x += ($w + self::GAP) * self::CHAR;
        }
        throw new \InvalidArgumentException($key);
    }

    private function colW(string $key): float
    {
        return self::COLS[$key] * self::CHAR;
    }

    private static function date(string $iso): string
    {
        return substr($iso, 8, 2) . '.' . substr($iso, 5, 2) . '.' . substr($iso, 0, 4);
    }

    /** tFPDF: Schriftquelle = resources/fonts, Metrik-Cache im Datenordner. */
    private function bootFonts(): void
    {
        $fonts = $this->config->appDir . '/resources/fonts/';
        if (!defined('_SYSTEM_TTFONTS')) {
            define('_SYSTEM_TTFONTS', $fonts);
        }
        if (!defined('FPDF_FONTPATH')) {
            $cache = $this->config->path('cache/pdf-fonts-' . substr(md5($fonts), 0, 8)) . '/';
            if (!is_dir($cache . 'unifont')) {
                @mkdir($cache . 'unifont', 0775, true);
            }
            define('FPDF_FONTPATH', is_dir($cache . 'unifont') ? $cache : dirname(__DIR__, 2) . '/vendor/setasign/tfpdf/font/');
        }
    }
}
