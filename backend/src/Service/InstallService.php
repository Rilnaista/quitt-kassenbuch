<?php

declare(strict_types=1);

namespace Quitt\Service;

use Psr\Log\LoggerInterface;
use Quitt\Db\Database;
use Quitt\Db\Migrator;
use Quitt\Http\ApiException;

/**
 * Erstinstallation (genutzt von setup.php und bin/install.php):
 * Migrationen, Gruppenname, PIN, Wiederherstellungscode. Danach gilt die Installation als "installed".
 */
final class InstallService
{
    public function __construct(
        private readonly Database $db,
        private readonly Migrator $migrator,
        private readonly AuthService $auth,
        private readonly SettingsService $settings,
        private readonly LoggerInterface $log,
    ) {
    }

    public function isInstalled(): bool
    {
        return $this->auth->isInstalled();
    }

    /**
     * @return string Wiederherstellungscode (nur jetzt im Klartext verfuegbar)
     * @throws \RuntimeException wenn bereits installiert
     * @throws ApiException 422 bei ungueltiger Eingabe
     */
    public function install(string $groupName, string $pin): string
    {
        $this->migrator->migrate();
        if ($this->isInstalled()) {
            throw new \RuntimeException('Quitt ist bereits installiert.');
        }
        $errors = [];
        $groupName = trim($groupName);
        if ($groupName === '') {
            $errors['group_name'] = 'validation.required';
        } elseif (mb_strlen($groupName) > 100) {
            $errors['group_name'] = 'validation.too_long';
        }
        if (!AuthService::isValidPin($pin)) {
            $errors['pin'] = 'validation.pin_format';
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
        $code = $this->db->transaction(function () use ($groupName, $pin): string {
            $this->settings->set('group_name', $groupName);
            $this->auth->setPin($pin);
            return $this->auth->generateRecoveryCode();
        });
        $this->log->info('installed');
        return $code;
    }
}
