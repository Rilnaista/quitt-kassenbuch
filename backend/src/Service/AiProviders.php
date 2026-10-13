<?php

declare(strict_types=1);

namespace Quitt\Service;

use Quitt\App\Config;

/** Katalog der KI-Anbieter; Default-Modelle aus der Config (data/config.json: ai_default_models). */
final class AiProviders
{
    private const CATALOG = [
        'mistral' => ['name' => 'Mistral AI', 'needs_key' => true, 'needs_base_url' => false],
        'openai' => ['name' => 'OpenAI', 'needs_key' => true, 'needs_base_url' => false],
        'anthropic' => ['name' => 'Anthropic', 'needs_key' => true, 'needs_base_url' => false],
        'gemini' => ['name' => 'Google Gemini', 'needs_key' => true, 'needs_base_url' => false],
        'ollama' => ['name' => 'Ollama', 'needs_key' => false, 'needs_base_url' => true],
    ];

    public function __construct(private readonly Config $config)
    {
    }

    /** @return string[] */
    public function ids(): array
    {
        return array_keys(self::CATALOG);
    }

    public function has(string $id): bool
    {
        return isset(self::CATALOG[$id]);
    }

    public function defaultModel(string $id): ?string
    {
        $models = (array) $this->config->get('ai_default_models', []);
        return isset($models[$id]) ? (string) $models[$id] : null;
    }

    public function needsKey(string $id): bool
    {
        return self::CATALOG[$id]['needs_key'] ?? false;
    }

    public function needsBaseUrl(string $id): bool
    {
        return self::CATALOG[$id]['needs_base_url'] ?? false;
    }

    /** @return list<array{id: string, name: string, needs_key: bool, needs_base_url: bool, default_model: ?string}> */
    public function all(): array
    {
        $out = [];
        foreach (self::CATALOG as $id => $p) {
            $out[] = ['id' => $id] + $p + ['default_model' => $this->defaultModel($id)];
        }
        return $out;
    }
}
