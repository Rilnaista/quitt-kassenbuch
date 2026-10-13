<?php

declare(strict_types=1);

namespace Quitt\Tests\Api;

use Quitt\Tests\ApiTestCase;

final class ExportApiTest extends ApiTestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->install();
        $this->token = $this->login();
    }

    private function sync(array $entries): void
    {
        $r = $this->request('POST', '/sync', ['entries' => $entries], $this->token);
        self::assertSame(200, $r->getStatusCode(), (string) $r->getBody());
    }

    /** @return array<string, string> Dateiname => Inhalt */
    private function unzip(string $bytes): array
    {
        $path = $this->dataDir . '/x-' . bin2hex(random_bytes(4)) . '.xlsx';
        file_put_contents($path, $bytes);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path) === true, 'ZIP öffnet');
        $out = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $out[$zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        return $out;
    }

    public function testExportContent(): void
    {
        $this->sync([
            self::entry(1, ['type' => 'in', 'purpose' => 'Beitrag & Spende', 'reason' => null, 'category' => null, 'item' => null,
                'amount_cents' => 5000, 'date' => '2026-09-20']),
            self::entry(2, ['amount_cents' => 1250, 'date' => '2026-10-02', 'note' => 'für <Anna>']),
            self::entry(3, ['type' => 'in', 'purpose' => 'Spende', 'reason' => null, 'category' => null, 'item' => null,
                'amount_cents' => 3000, 'date' => '2026-10-03']),
            self::entry(4, ['amount_cents' => 999, 'date' => '2026-10-04', 'deleted_at' => '2026-10-05T00:00:00Z', 'updated_at' => '2026-10-05T00:00:00Z']),
        ]);
        $r = $this->request('GET', '/export.xlsx?from=2026-10-01&to=2026-10-31', null, $this->token);
        self::assertSame(200, $r->getStatusCode());
        self::assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $r->getHeaderLine('Content-Type'));
        self::assertStringContainsString('quitt-buchungen-2026-10-01-2026-10-31.xlsx', $r->getHeaderLine('Content-Disposition'));
        $files = $this->unzip((string) $r->getBody());
        foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/styles.xml', 'xl/worksheets/sheet1.xml'] as $f) {
            self::assertArrayHasKey($f, $files);
            self::assertNotFalse(simplexml_load_string($files[$f]), $f . ' ist wohlgeformt');
        }
        $sheet = $files['xl/worksheets/sheet1.xml'];
        self::assertStringContainsString('Verwendungszweck/Bezug', $sheet);
        self::assertStringContainsString('Notiz', $sheet);
        self::assertStringContainsString('<autoFilter', $sheet);
        self::assertStringContainsString('state="frozen"', $sheet);
        self::assertStringContainsString('Anfangssaldo', $sheet);
        self::assertStringContainsString('für &lt;Anna&gt;', $sheet);
        // Zeile 2: Anfangssaldo 50,00; Zeile 3: -12,50 -> 37,50; Zeile 4: +30 -> Endsaldo 67,50; Papierkorb fehlt
        self::assertMatchesRegularExpression('#<c r="E2" s="3"><v>50\.00</v>#', $sheet);
        self::assertMatchesRegularExpression('#<c r="D3" s="3"><v>-12\.50</v>#', $sheet);
        self::assertMatchesRegularExpression('#<c r="E4" s="3"><v>67\.50</v>#', $sheet);
        self::assertStringNotContainsString('<row r="5"', $sheet);
        self::assertStringNotContainsString('9.99', $sheet);
        self::assertStringContainsString('<c r="B3" s="2"><v>46297</v>', $sheet); // 2026-10-02
    }

    public function testValidationAndAuth(): void
    {
        $this->assertError($this->request('GET', '/export.xlsx?from=2026-10-31&to=2026-10-01', null, $this->token), 422, 'validation_failed');
        $this->assertError($this->request('GET', '/export.xlsx?from=2026-10-01&to=2026-10-31'), 401, 'unauthorized');
        $this->setReadWithoutPin(true);
        self::assertSame(200, $this->request('GET', '/export.xlsx?from=2026-10-01&to=2026-10-31')->getStatusCode());
    }

    public function testTicketAccess(): void
    {
        $r = $this->request('POST', '/downloads', ['target' => 'export', 'params' => ['from' => '2026-10-01', 'to' => '2026-10-31']], $this->token);
        self::assertSame(200, $r->getStatusCode(), (string) $r->getBody());
        $url = $this->decode($r)['url'];
        $path = substr($url, strlen('api/v1'));
        $ok = $this->request('GET', $path);
        self::assertSame(200, $ok->getStatusCode());
        self::assertStringStartsWith('PK', (string) $ok->getBody());
        $this->assertError($this->request('GET', $path), 401, 'unauthorized');
        $this->assertError($this->request('POST', '/downloads', ['target' => 'export', 'params' => ['from' => 'x', 'to' => 'y']], $this->token), 422, 'validation_failed');
    }
}
