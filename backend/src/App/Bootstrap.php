<?php

declare(strict_types=1);

namespace Quitt\App;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;
use Quitt\Db\Database;
use Quitt\Db\Meta;
use Quitt\Db\Migrator;
use Quitt\Db\Sequences;
use Quitt\Http\ErrorHandler;
use Quitt\Http\Json;
use Quitt\Http\Middleware\MigrationMiddleware;
use Quitt\Http\Middleware\NoStoreMiddleware;
use Quitt\Http\Registrar;
use Quitt\I18n\Lang;
use Quitt\Log\Logger;
use Quitt\Service\AiProviders;
use Quitt\Service\AuthService;
use Quitt\Service\BackupService;
use Quitt\Service\ClientLogService;
use Quitt\Service\DownloadService;
use Quitt\Service\InstallService;
use Quitt\Service\RateLimiter;
use Quitt\Service\ReceiptService;
use Quitt\Service\SettingsService;
use Quitt\Service\SyncService;
use Quitt\Service\TrashService;
use Quitt\Support\Clock;
use Quitt\Support\Environment;
use Quitt\Support\SystemClock;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Zusammenbau: Container (Kerndienste) -> Slim-App (Module aus modules/*.php) -> run.
 * Middleware-Reihenfolge (aussen -> innen): NoStore, Error, Migration, Routing, Access (je Route).
 */
final class Bootstrap
{
    public static function container(Config $config, ?Clock $clock = null): Container
    {
        $c = new Container();
        $c->instance(Config::class, $config);
        $c->instance(Clock::class, $clock ?? new SystemClock());
        $c->instance('base_path', '');

        $c->set(ResponseFactoryInterface::class, fn () => new ResponseFactory());
        $c->set(Lang::class, fn () => Lang::load($config->appDir . '/lang', 'de'));
        $c->set(Logger::class, fn (Container $c) => new Logger(
            $config->path('logs'),
            'app',
            (string) $config->get('log_level', 'info'),
            (int) $config->get('log_max_files', 6),
            $c->get(Clock::class)
        ));
        $c->set(LoggerInterface::class, fn (Container $c) => $c->get(Logger::class));
        $c->set(Database::class, fn () => new Database($config->dbFile()));
        $c->set(Meta::class, fn (Container $c) => new Meta($c->get(Database::class)));
        $c->set(Sequences::class, fn (Container $c) => new Sequences($c->get(Database::class)));
        $c->set(BackupService::class, fn (Container $c) => new BackupService(
            $config, $c->get(Database::class), $c->get(Clock::class), $c->get(Logger::class)->withChannel('backup')
        ));
        $c->set(Migrator::class, fn (Container $c) => new Migrator(
            $c->get(Database::class),
            $config->appDir . '/migrations',
            $c->get(BackupService::class),
            $c->get(Logger::class)->withChannel('migration'),
            $config->path('.migrate.lock')
        ));
        $c->set('limiter.login', fn (Container $c) => new RateLimiter($c->get(Database::class), $c->get(Clock::class), 'login'));
        $c->set('limiter.reset', fn (Container $c) => new RateLimiter($c->get(Database::class), $c->get(Clock::class), 'reset'));
        $c->set(AuthService::class, fn (Container $c) => new AuthService(
            $c->get(Database::class), $c->get(Meta::class), $c->get(Clock::class), $config,
            $c->get('limiter.login'), $c->get('limiter.reset'), $c->get(Logger::class)->withChannel('auth')
        ));
        $c->set(AiProviders::class, fn () => new AiProviders($config));
        $c->set(SettingsService::class, fn (Container $c) => new SettingsService(
            $c->get(Database::class), $c->get(AiProviders::class), $c->get(Lang::class)
        ));
        $c->set(InstallService::class, fn (Container $c) => new InstallService(
            $c->get(Database::class), $c->get(Migrator::class), $c->get(AuthService::class),
            $c->get(SettingsService::class), $c->get(Logger::class)->withChannel('install')
        ));
        $c->set(ReceiptService::class, fn (Container $c) => new ReceiptService(
            $c->get(Database::class), $c->get(Sequences::class), $c->get(Clock::class), $config, $c->get(Logger::class)->withChannel('receipts')
        ));
        $c->set(SyncService::class, fn (Container $c) => new SyncService(
            $c->get(Database::class), $c->get(Sequences::class), $c->get(ReceiptService::class),
            $c->get(Clock::class), $config, $c->get(Logger::class)->withChannel('sync')
        ));
        $c->set(TrashService::class, fn (Container $c) => new TrashService(
            $c->get(Database::class), $c->get(Sequences::class), $c->get(ReceiptService::class),
            $c->get(Clock::class), $c->get(Logger::class)->withChannel('trash')
        ));
        $c->set(DownloadService::class, fn (Container $c) => new DownloadService($c->get(Database::class), $c->get(Clock::class), $config));
        $c->set(ClientLogService::class, fn (Container $c) => new ClientLogService(
            $c->get(Logger::class)->withChannel('client'), $c->get(Clock::class), $config
        ));
        return $c;
    }

    /**
     * @param string $basePath Installationspfad ("" oder "/unterordner"); API liegt unter <basePath>/api/v1
     */
    public static function app(Container $c, string $basePath): App
    {
        $config = $c->get(Config::class);
        $c->instance('base_path', $basePath);

        $app = AppFactory::create($c->get(ResponseFactoryInterface::class), $c);
        $app->setBasePath($basePath . '/api/v1');

        $registrar = new Registrar($app, $c);
        $c->instance(Registrar::class, $registrar);
        $modules = glob($config->appDir . '/modules/*.php') ?: [];
        sort($modules, SORT_STRING);
        foreach ($modules as $file) {
            $module = require $file;
            if (!is_callable($module)) {
                throw new \RuntimeException('Modul liefert keine Funktion: ' . basename($file));
            }
            $module($registrar);
        }

        $app->addRoutingMiddleware();
        $app->add(new MigrationMiddleware($c->get(Migrator::class)));
        $errors = $app->addErrorMiddleware(false, false, false);
        $errors->setDefaultErrorHandler(new ErrorHandler(
            $c->get(ResponseFactoryInterface::class),
            $c->get(Lang::class),
            $c->get(Logger::class)->withChannel('http')
        ));
        $app->add(new NoStoreMiddleware());
        return $app;
    }

    /**
     * Einstieg fuer public/api/index.php (Entwicklung) bzw. api/index.php im Release-Paket.
     * @param string|null $defaultDataDir Release-Paket: <installationsordner>/data (neben app/)
     */
    public static function run(string $appDir, ?string $defaultDataDir = null): void
    {
        // Apache/CGI reicht Authorization oft nur als REDIRECT_HTTP_AUTHORIZATION durch (.htaccess)
        if (empty($_SERVER['HTTP_AUTHORIZATION']) && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $_SERVER['HTTP_AUTHORIZATION'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        $config = Config::fromEnvironment($appDir, $defaultDataDir);
        try {
            DataDir::ensure($config->dataDir);
        } catch (\Throwable) {
            $lang = Lang::load($appDir . '/lang', 'de');
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode(['error' => ['code' => 'data_dir_unwritable', 'message' => $lang->t('error.data_dir_unwritable')]], Json::FLAGS);
            return;
        }
        $c = self::container($config);
        self::app($c, Environment::basePath($_SERVER))->run();
    }
}
