<?php

declare(strict_types=1);

namespace Quitt\Service;

use Quitt\I18n\Lang;

/**
 * Excel-Export (.xlsx) der Buchungen eines Zeitraums, ohne Fremdbibliothek (ZipArchive + OOXML).
 * Saldo/Anfangssaldo kommen aus StatementService::ledger() (gleiche Logik wie der Kontoauszug).
 */
final class ExportService
{
    public const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private const COLS = 'ABCDEFGHIJ';

    public function __construct(
        private readonly StatementService $statement,
        private readonly Lang $lang,
    ) {
    }

    public static function filename(string $from, string $to): string
    {
        return 'quitt-buchungen-' . $from . '-' . $to . '.xlsx';
    }

    public function render(mixed $from, mixed $to): string
    {
        $this->statement->validateRange($from, $to);
        $ledger = $this->statement->ledger($from, $to);
        $t = fn (string $k): string => $this->lang->t($k);

        $rows = [];
        $rows[] = $this->row(1, [
            ['s', $t('export.col.number'), 1], ['s', $t('export.col.date'), 1], ['s', $t('export.col.kind'), 1],
            ['s', $t('export.col.amount'), 1], ['s', $t('export.col.balance'), 1], ['s', $t('export.col.text'), 1],
            ['s', $t('export.col.reason'), 1], ['s', $t('export.col.category'), 1], ['s', $t('export.col.note'), 1],
            ['s', $t('export.col.receipt'), 1],
        ]);
        $n = 2;
        $rows[] = $this->row($n++, [
            null, ['d', self::serial($from), 2], null, null, ['m', $ledger['opening'], 3],
            ['s', $t('statement.opening'), 0], null, null, null, null,
        ]);
        foreach ($ledger['rows'] as $r) {
            $in = $r['type'] === 'in';
            $amount = $in ? (int) $r['amount_cents'] : -(int) $r['amount_cents'];
            $rows[] = $this->row($n++, [
                ['n', (int) $r['number'], 0],
                ['d', self::serial((string) $r['date']), 2],
                ['s', $t($in ? 'export.in' : 'export.out'), 0],
                ['m', $amount, 3],
                ['m', (int) $r['balance'], 3],
                ['s', $in ? $r['purpose'] : $r['item'], 0],
                ['s', $in ? null : $r['reason'], 0],
                ['s', $in ? null : $r['category'], 0],
                ['s', $r['note'], 0],
                ['s', $in ? null : $t($r['receipt_id'] !== null ? 'export.yes' : 'export.no'), 0],
            ]);
        }
        $last = $n - 1;
        $widths = [8, 12, 12, 14, 14, 36, 28, 22, 36, 8];
        $colsXml = '';
        foreach ($widths as $i => $w) {
            $colsXml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
        }
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<dimension ref="A1:J' . $last . '"/>'
            . '<sheetViews><sheetView workbookViewId="0" tabSelected="1"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
            . '<selection pane="bottomLeft" activeCell="A2" sqref="A2"/></sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/>'
            . '<cols>' . $colsXml . '</cols>'
            . '<sheetData>' . implode('', $rows) . '</sheetData>'
            . '<autoFilter ref="A1:J' . $last . '"/>'
            . '</worksheet>';

        $sheetName = self::esc($t('export.sheet'));
        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                . '</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                . '</Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . '<sheets><sheet name="' . $sheetName . '" sheetId="1" r:id="rId1"/></sheets>'
                . '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">\'' . $sheetName . '\'!$A$1:$J$' . $last . '</definedName></definedNames>'
                . '</workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                . '</Relationships>',
            'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                . '<numFmts count="2"><numFmt numFmtId="164" formatCode="dd\.mm\.yyyy"/>'
                . '<numFmt numFmtId="165" formatCode="#,##0.00\ &quot;€&quot;;\-#,##0.00\ &quot;€&quot;"/></numFmts>'
                . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
                . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
                . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
                . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
                . '<cellXfs count="4">'
                . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
                . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
                . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
                . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
                . '</cellXfs>'
                . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
                . '</styleSheet>',
            'xl/worksheets/sheet1.xml' => $sheet,
        ];

        $tmp = tempnam(sys_get_temp_dir(), 'quitt-xlsx');
        if ($tmp === false) {
            throw new \RuntimeException('Temporäre Datei nicht anlegbar');
        }
        try {
            $zip = new \ZipArchive();
            if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('XLSX nicht erzeugbar');
            }
            foreach ($files as $name => $content) {
                $zip->addFromString($name, $content);
            }
            $zip->close();
            return (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * @param list<array{0: string, 1: mixed, 2: int}|null> $cells t: s Text, n Zahl, d Datum (Seriennr.), m Cent -> Euro
     */
    private function row(int $r, array $cells): string
    {
        $out = '<row r="' . $r . '">';
        foreach ($cells as $i => $c) {
            if ($c === null || $c[1] === null || $c[1] === '') {
                continue;
            }
            $ref = self::COLS[$i] . $r;
            [$type, $val, $style] = $c;
            $s = $style > 0 ? ' s="' . $style . '"' : '';
            if ($type === 's') {
                $out .= '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">' . self::esc((string) $val) . '</t></is></c>';
            } elseif ($type === 'm') {
                $cents = (int) $val;
                $str = ($cents < 0 ? '-' : '') . intdiv(abs($cents), 100) . '.' . sprintf('%02d', abs($cents) % 100);
                $out .= '<c r="' . $ref . '"' . $s . '><v>' . $str . '</v></c>';
            } else {
                $out .= '<c r="' . $ref . '"' . $s . '><v>' . (int) $val . '</v></c>';
            }
        }
        return $out . '</row>';
    }

    /** Excel-Datumsseriennummer (Tage seit 1899-12-30). */
    private static function serial(string $date): int
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
        $base = new \DateTimeImmutable('1899-12-30', new \DateTimeZone('UTC'));
        return (int) $base->diff($d)->days;
    }

    private static function esc(string $s): string
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? $s;
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
