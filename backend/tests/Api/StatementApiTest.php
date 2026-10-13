<?php

declare(strict_types=1);

namespace Quitt\Tests\Api;

use Quitt\Service\StatementService;
use Quitt\Tests\ApiTestCase;

final class StatementApiTest extends ApiTestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->install();
        $this->token = $this->login();
    }

    /** @param list<array<string, mixed>> $entries */
    private function sync(array $entries): void
    {
        $r = $this->request('POST', '/sync', ['entries' => $entries], $this->token);
        self::assertSame(200, $r->getStatusCode(), (string) $r->getBody());
    }

    private function fetch(string $url): \Psr\Http\Message\ResponseInterface
    {
        return $this->request('GET', substr($url, strlen('api/v1')));
    }

    public function testReturnsPdf(): void
    {
        $this->sync([self::entry(1)]);
        $r = $this->request('GET', '/statement.pdf?from=2026-10-01&to=2026-10-31', null, $this->token);
        self::assertSame(200, $r->getStatusCode());
        self::assertSame('application/pdf', $r->getHeaderLine('Content-Type'));
        self::assertStringContainsString('kontoauszug-2026-10-01_2026-10-31.pdf', $r->getHeaderLine('Content-Disposition'));
        self::assertStringStartsWith('%PDF-', (string) $r->getBody());
    }

    public function testManyEntriesGiveSeveralPages(): void
    {
        $entries = [];
        for ($i = 1; $i <= 150; $i++) {
            $entries[] = self::entry($i, ['date' => sprintf('2026-10-%02d', 1 + $i % 28)]);
        }
        $this->sync($entries);
        $r = $this->request('GET', '/statement.pdf?from=2026-10-01&to=2026-10-31', null, $this->token);
        self::assertSame(200, $r->getStatusCode());
        $model = $this->c->get(StatementService::class)->build('2026-10-01', '2026-10-31');
        $pages = count($model['pages']);
        self::assertGreaterThan(2, $pages);
        self::assertSame($pages, preg_match_all('#/Type\s*/Page\b(?!s)#', (string) $r->getBody()));
    }

    public function testValidation(): void
    {
        $this->assertError($this->request('GET', '/statement.pdf?from=2026-10-01', null, $this->token), 422, 'validation_failed');
        $this->assertError($this->request('GET', '/statement.pdf?from=2026-10-31&to=2026-10-01', null, $this->token), 422, 'validation_failed');
        $this->assertError($this->request('GET', '/statement.pdf?from=2026-02-30&to=2026-10-01', null, $this->token), 422, 'validation_failed');
    }

    public function testRequiresAuth(): void
    {
        $this->assertError($this->request('GET', '/statement.pdf?from=2026-10-01&to=2026-10-31'), 401, 'unauthorized');
        $this->setReadWithoutPin(true);
        self::assertSame(200, $this->request('GET', '/statement.pdf?from=2026-10-01&to=2026-10-31')->getStatusCode());
    }

    public function testTicketAccessIsSingleUse(): void
    {
        $r = $this->request('POST', '/downloads', ['target' => 'statement', 'params' => ['from' => '2026-10-01', 'to' => '2026-10-31']], $this->token);
        self::assertSame(200, $r->getStatusCode(), (string) $r->getBody());
        $url = $this->decode($r)['url'];
        self::assertMatchesRegularExpression('#^api/v1/statement\.pdf\?from=2026-10-01&to=2026-10-31&ticket=[0-9a-f]{48}$#', $url);
        $first = $this->fetch($url);
        self::assertSame(200, $first->getStatusCode());
        self::assertSame('application/pdf', $first->getHeaderLine('Content-Type'));
        $this->assertError($this->fetch($url), 401, 'unauthorized');
        $this->assertError($this->request('POST', '/downloads', ['target' => 'statement', 'params' => ['from' => 'x', 'to' => '2026-10-31']], $this->token), 422, 'validation_failed');
    }

    public function testBalancesTrashAndText(): void
    {
        $this->sync([
            self::entry(1, ['type' => 'in', 'purpose' => 'Beitrag', 'reason' => null, 'category' => null, 'item' => null, 'amount_cents' => 10000, 'date' => '2026-09-20']),
            self::entry(2, ['amount_cents' => 2500, 'date' => '2026-09-25']),
            self::entry(3, ['type' => 'in', 'purpose' => 'Spende', 'reason' => null, 'category' => null, 'item' => null, 'amount_cents' => 5000, 'date' => '2026-10-02']),
            self::entry(4, ['amount_cents' => 1234, 'date' => '2026-10-03', 'reason' => 'Ausflug', 'category' => 'Anna', 'item' => 'Eis']),
            self::entry(5, ['amount_cents' => 9999, 'date' => '2026-10-04', 'deleted_at' => '2026-10-05T00:00:00Z', 'updated_at' => '2026-10-05T00:00:00Z']),
        ]);
        $m = $this->c->get(StatementService::class)->build('2026-10-01', '2026-10-31');
        self::assertSame(7500, $m['opening']);
        self::assertSame(7500 + 5000 - 1234, $m['closing']);
        $rows = $m['pages'][0]['rows'];
        self::assertCount(2, $rows);
        self::assertSame(['Ausflug · Anna · Eis'], $rows[1]['lines']);
        self::assertSame(['Spende'], $rows[0]['lines']);
        self::assertSame(1234, $rows[1]['out']);
        self::assertSame(5000, $rows[0]['in']);
    }
}
