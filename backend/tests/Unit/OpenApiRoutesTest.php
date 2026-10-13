<?php

declare(strict_types=1);

namespace Quitt\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Quitt\App\Bootstrap;
use Quitt\Http\Access;
use Quitt\Http\Registrar;
use Quitt\Tests\TestHelpers;

/** Jede registrierte Route steht in openapi.json - mit gleicher Zugriffsstufe und Ticket-Angabe. */
final class OpenApiRoutesTest extends TestCase
{
    use TestHelpers;

    private array $spec;

    protected function setUp(): void
    {
        $this->spec = json_decode((string) file_get_contents(self::appDir() . '/public/api/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->bootContainer();
    }

    protected function tearDown(): void
    {
        $this->cleanupDataDir();
    }

    public function testSpecIsOpenApi3(): void
    {
        self::assertStringStartsWith('3.', $this->spec['openapi']);
        self::assertNotEmpty($this->spec['paths']);
    }

    public function testEveryOperationDeclaresAccess(): void
    {
        foreach ($this->spec['paths'] as $path => $ops) {
            foreach ($ops as $method => $op) {
                self::assertContains($op['x-quitt-access'] ?? null, Access::ALL, "$method $path ohne gueltiges x-quitt-access");
                self::assertIsBool($op['x-quitt-ticket'] ?? null, "$method $path ohne x-quitt-ticket");
                self::assertNotEmpty($op['responses'] ?? [], "$method $path ohne responses");
            }
        }
    }

    public function testEveryRegisteredRouteIsDocumented(): void
    {
        Bootstrap::app($this->c, '');
        $routes = $this->c->get(Registrar::class)->routes();
        self::assertNotEmpty($routes);
        foreach ($routes as $route) {
            $method = strtolower($route['method']);
            $op = $this->spec['paths'][$route['path']][$method] ?? null;
            self::assertNotNull($op, "Route {$route['method']} {$route['path']} fehlt in openapi.json");
            self::assertSame($route['access'], $op['x-quitt-access'], "Zugriffsstufe {$route['method']} {$route['path']}");
            self::assertSame($route['ticket'], $op['x-quitt-ticket'], "Ticket-Angabe {$route['method']} {$route['path']}");
        }
    }

    public function testContractEndpointsArePresent(): void
    {
        // Vollstaendigkeit gegenueber api-contract.md (auch noch nicht implementierte Endpunkte)
        $expected = [
            'get /health', 'get /system/info', 'post /auth/login', 'get /auth/status', 'post /auth/logout', 'post /auth/pin',
            'get /auth/reset', 'post /auth/reset', 'get /sync', 'post /sync', 'delete /entries/{id}', 'post /trash/empty',
            'put /receipts/{id}', 'get /receipts/{id}/file', 'post /receipts/{id}/recognize', 'get /settings', 'put /settings',
            'post /settings/recognition/test', 'get /settings/ai-providers', 'post /downloads', 'get /statement.pdf',
            'get /backup', 'post /restore', 'get /backups', 'post /backups/{name}/restore', 'get /update/check',
            'post /update/apply', 'get /logs', 'get /diagnostics', 'post /logs/client',
        ];
        foreach ($expected as $e) {
            [$m, $p] = explode(' ', $e);
            self::assertArrayHasKey($m, $this->spec['paths'][$p] ?? [], "$e fehlt in openapi.json");
        }
    }

    public function testDocsUiIsBundledLocally(): void
    {
        $html = (string) file_get_contents(self::appDir() . '/public/api/docs/index.html');
        self::assertStringContainsString('src="redoc.standalone.js"', $html);
        self::assertStringNotContainsString('cdn.', $html);
        self::assertFileExists(self::appDir() . '/public/api/docs/redoc.standalone.js');
    }
}
