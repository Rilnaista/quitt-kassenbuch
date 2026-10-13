<?php

declare(strict_types=1);

namespace Quitt\Service;

use Quitt\Db\Database;
use Quitt\Http\ApiException;
use Quitt\I18n\Lang;

/**
 * Serverseitige Einstellungen (fuer alle Geraete). ai_key ist write-only:
 * publicView() liefert stattdessen ai_key_set. Werte JSON-kodiert in Tabelle settings.
 */
final class SettingsService
{
    public const MODES = ['off', 'local', 'ai'];
    private const MAX_NAME = 100;
    private const MAX_MODEL = 100;
    private const MAX_URL = 500;
    private const MAX_KEY = 500;

    public function __construct(
        private readonly Database $db,
        private readonly AiProviders $providers,
        private readonly Lang $lang,
    ) {
    }

    /** @return array<string, mixed> */
    public function defaults(): array
    {
        return [
            'group_name' => $this->lang->t('settings.default_group_name'),
            'read_without_pin' => false,
            'recognition_mode' => 'local',
            'ai_provider' => null,
            'ai_model' => null,
            'ai_base_url' => null,
            'ai_key' => null,
        ];
    }

    /** Alle Werte inkl. ai_key (nur intern verwenden!). @return array<string, mixed> */
    public function all(): array
    {
        $values = $this->defaults();
        if (!$this->db->tableExists('settings')) {
            return $values;
        }
        foreach ($this->db->all('SELECT key, value FROM settings') as $row) {
            if (array_key_exists($row['key'], $values)) {
                $values[$row['key']] = json_decode((string) $row['value'], true);
            }
        }
        return $values;
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    /** @return array<string, mixed> ohne ai_key, mit ai_key_set */
    public function publicView(): array
    {
        $all = $this->all();
        $all['ai_key_set'] = is_string($all['ai_key']) && $all['ai_key'] !== '';
        unset($all['ai_key']);
        return $all;
    }

    /**
     * Teil-Update mit Validierung. ai_key "" oder null loescht den Schluessel.
     * @param array<string, mixed> $patch
     * @return array<string, mixed> publicView()
     */
    public function update(array $patch): array
    {
        unset($patch['ai_key_set']); // Rundlauf GET->PUT tolerieren
        $errors = [];
        $clean = [];
        $known = array_keys($this->defaults());
        foreach ($patch as $key => $value) {
            if (!in_array($key, $known, true)) {
                $errors[$key] = 'validation.unknown_field';
                continue;
            }
            switch ($key) {
                case 'group_name':
                    if (!is_string($value) || trim($value) === '') {
                        $errors[$key] = 'validation.required';
                    } elseif (mb_strlen(trim($value)) > self::MAX_NAME) {
                        $errors[$key] = 'validation.too_long';
                    } else {
                        $clean[$key] = trim($value);
                    }
                    break;
                case 'read_without_pin':
                    is_bool($value) ? $clean[$key] = $value : $errors[$key] = 'validation.boolean';
                    break;
                case 'recognition_mode':
                    in_array($value, self::MODES, true) ? $clean[$key] = $value : $errors[$key] = 'validation.invalid_choice';
                    break;
                case 'ai_provider':
                    ($value === null || (is_string($value) && $this->providers->has($value)))
                        ? $clean[$key] = $value
                        : $errors[$key] = 'validation.invalid_choice';
                    break;
                case 'ai_model':
                    $r = $this->optionalString($value, self::MAX_MODEL);
                    $r === false ? $errors[$key] = (is_string($value) ? 'validation.too_long' : 'validation.string') : $clean[$key] = $r;
                    break;
                case 'ai_base_url':
                    $r = $this->optionalString($value, self::MAX_URL);
                    if ($r === false || ($r !== null && !preg_match('#^https?://[^\s/]+#i', $r))) {
                        $errors[$key] = 'validation.url';
                    } else {
                        $clean[$key] = $r === null ? null : rtrim($r, '/');
                    }
                    break;
                case 'ai_key':
                    $r = $this->optionalString($value, self::MAX_KEY);
                    $r === false ? $errors[$key] = (is_string($value) ? 'validation.too_long' : 'validation.string') : $clean[$key] = $r;
                    break;
            }
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
        $this->db->transaction(function () use ($clean): void {
            foreach ($clean as $key => $value) {
                $this->set($key, $value);
            }
        });
        return $this->publicView();
    }

    /** Intern setzen ohne Validierung (Installation, Restore). */
    public function set(string $key, mixed $value): void
    {
        $this->db->run(
            'INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value',
            [$key, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]
        );
    }

    /** @return string|null|false false = zu lang/ungueltig; "" -> null */
    private function optionalString(mixed $value, int $max): string|null|false
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            return false;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        return mb_strlen($value) > $max ? false : $value;
    }
}
