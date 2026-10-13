<?php

declare(strict_types=1);

namespace Quitt\Service;

use Psr\Log\LoggerInterface;
use Quitt\App\Config;
use Quitt\Db\Database;
use Quitt\Db\Meta;
use Quitt\Http\ApiException;
use Quitt\Support\Clock;
use Quitt\Support\Time;

/**
 * PIN, Tokens (nur SHA-256 gespeichert, gleitend gueltig), Wiederherstellungscode, reset-pin-Datei.
 * "installiert" = PIN-Hash vorhanden.
 */
final class AuthService
{
    public const META_PIN = 'pin_hash';
    public const META_RECOVERY = 'recovery_hash';
    public const RESET_FILE = 'reset-pin';

    /** ohne 0/O, 1/I/L, um Verwechslungen beim Abschreiben zu vermeiden */
    private const RECOVERY_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public function __construct(
        private readonly Database $db,
        private readonly Meta $meta,
        private readonly Clock $clock,
        private readonly Config $config,
        private readonly RateLimiter $loginLimiter,
        private readonly RateLimiter $resetLimiter,
        private readonly LoggerInterface $log,
    ) {
    }

    public static function isValidPin(mixed $pin): bool
    {
        return is_string($pin) && preg_match('/^\d{4,8}$/', $pin) === 1;
    }

    public function isInstalled(): bool
    {
        return $this->db->tableExists('meta') && $this->meta->get(self::META_PIN) !== null;
    }

    public function setPin(string $pin): void
    {
        if (!self::isValidPin($pin)) {
            throw ApiException::validation(['new_pin' => 'validation.pin_format']);
        }
        $this->meta->set(self::META_PIN, password_hash($pin, PASSWORD_DEFAULT));
    }

    public function verifyPin(string $pin): bool
    {
        $hash = $this->meta->get(self::META_PIN);
        return $hash !== null && password_verify($pin, $hash);
    }

    /** @return array{token: string, expires_at: string} */
    public function login(mixed $pin): array
    {
        $this->loginLimiter->assertNotLocked();
        if (!is_string($pin) || !$this->verifyPin($pin)) {
            $lock = $this->loginLimiter->fail();
            $this->log->warning('login failed', ['failures' => $this->loginLimiter->failures(), 'lock_seconds' => $lock]);
            throw ApiException::unauthorized();
        }
        $this->loginLimiter->reset();
        $this->log->info('login ok');
        return $this->issueToken();
    }

    /** @return array{token: string, expires_at: string} */
    public function issueToken(): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $now = $this->clock->now();
        $expires = $now->modify('+' . $this->lifetimeDays() . ' days');
        $this->db->run(
            'INSERT INTO auth_tokens (token_hash, created_at, last_used_at, expires_at) VALUES (?, ?, ?, ?)',
            [hash('sha256', $token), Time::iso($now), Time::iso($now), Time::iso($expires)]
        );
        return ['token' => $token, 'expires_at' => Time::iso($expires)];
    }

    /** Prueft ein Token und verlaengert es gleitend. @return string|null Token-Hash */
    public function validateToken(string $token): ?string
    {
        if ($token === '' || strlen($token) > 200) {
            return null;
        }
        $hash = hash('sha256', $token);
        $row = $this->db->one('SELECT last_used_at, expires_at FROM auth_tokens WHERE token_hash = ?', [$hash]);
        if ($row === null) {
            return null;
        }
        $now = $this->clock->now();
        if ((string) $row['expires_at'] <= Time::iso($now)) {
            $this->db->run('DELETE FROM auth_tokens WHERE token_hash = ?', [$hash]);
            return null;
        }
        // Schreiblast begrenzen: hoechstens einmal pro Minute verlaengern
        if ((string) $row['last_used_at'] <= Time::iso($now->modify('-60 seconds'))) {
            $this->db->run(
                'UPDATE auth_tokens SET last_used_at = ?, expires_at = ? WHERE token_hash = ?',
                [Time::iso($now), Time::iso($now->modify('+' . $this->lifetimeDays() . ' days')), $hash]
            );
        }
        return $hash;
    }

    public function revokeToken(string $hash): void
    {
        $this->db->run('DELETE FROM auth_tokens WHERE token_hash = ?', [$hash]);
    }

    public function revokeAllTokens(): void
    {
        $this->db->run('DELETE FROM auth_tokens');
    }

    /** PIN aendern; alte PIN noetig, Fehlversuche zaehlen wie beim Login. Andere Tokens bleiben gueltig. */
    public function changePin(mixed $oldPin, mixed $newPin): void
    {
        $this->loginLimiter->assertNotLocked();
        if (!is_string($oldPin) || !$this->verifyPin($oldPin)) {
            $this->loginLimiter->fail();
            $this->log->warning('pin change: wrong old pin');
            throw ApiException::validation(['old_pin' => 'validation.old_pin_wrong']);
        }
        if (!self::isValidPin($newPin)) {
            throw ApiException::validation(['new_pin' => 'validation.pin_format']);
        }
        $this->loginLimiter->reset();
        $this->setPin($newPin);
        $this->log->info('pin changed');
    }

    /** Erzeugt einen neuen Wiederherstellungscode (ersetzt den alten). */
    public function generateRecoveryCode(): string
    {
        $chars = '';
        $max = strlen(self::RECOVERY_ALPHABET) - 1;
        for ($i = 0; $i < 16; $i++) {
            $chars .= self::RECOVERY_ALPHABET[random_int(0, $max)];
        }
        $code = implode('-', str_split($chars, 4));
        $this->meta->set(self::META_RECOVERY, password_hash(self::normalizeCode($code), PASSWORD_DEFAULT));
        return $code;
    }

    public function verifyRecoveryCode(string $code): bool
    {
        $hash = $this->meta->get(self::META_RECOVERY);
        return $hash !== null && password_verify(self::normalizeCode($code), $hash);
    }

    public static function normalizeCode(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    public function resetFilePath(): string
    {
        return $this->config->path(self::RESET_FILE);
    }

    public function resetFilePresent(): bool
    {
        return is_file($this->resetFilePath());
    }

    /**
     * PIN zuruecksetzen per Wiederherstellungscode ODER reset-pin-Datei.
     * Alle Tokens werden ungueltig; neuer Code + neues Token fuer dieses Geraet.
     * @return array{recovery_code: string, token: string, expires_at: string}
     */
    public function reset(mixed $recoveryCode, mixed $newPin): array
    {
        $viaFile = $this->resetFilePresent();
        if (!$viaFile) {
            $this->resetLimiter->assertNotLocked();
        }
        if (!self::isValidPin($newPin)) {
            throw ApiException::validation(['new_pin' => 'validation.pin_format']);
        }
        if (!$viaFile) {
            if (!is_string($recoveryCode) || $recoveryCode === '' || !$this->verifyRecoveryCode($recoveryCode)) {
                $lock = $this->resetLimiter->fail();
                $this->log->warning('pin reset failed', ['failures' => $this->resetLimiter->failures(), 'lock_seconds' => $lock]);
                throw ApiException::unauthorized();
            }
            $this->resetLimiter->reset();
        }

        $result = $this->db->transaction(function () use ($newPin): array {
            $this->setPin($newPin);
            $this->revokeAllTokens();
            $code = $this->generateRecoveryCode();
            return ['recovery_code' => $code] + $this->issueToken();
        });
        $this->loginLimiter->reset();

        if ($viaFile && !@unlink($this->resetFilePath())) {
            $this->log->error('reset-pin file could not be deleted');
        }
        $this->log->warning('pin reset', ['via' => $viaFile ? 'file' : 'recovery_code']);
        return $result;
    }

    private function lifetimeDays(): int
    {
        return max(1, (int) $this->config->get('token_lifetime_days', 365));
    }
}
