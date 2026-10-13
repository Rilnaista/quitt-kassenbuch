<?php

declare(strict_types=1);

namespace Quitt\Recognition;

use Psr\Log\LoggerInterface;
use Quitt\Http\ApiException;
use Quitt\I18n\Lang;
use Quitt\Service\AiProviders;
use Quitt\Service\ReceiptService;
use Quitt\Service\SettingsService;

/**
 * KI-Belegerkennung (REQ-010/011, Serverteil). Ohne HTTP-Schicht testbar (HttpTransport austauschbar).
 * Der Schluessel wird nie geloggt oder in Meldungen uebernommen.
 */
final class RecognitionService
{
    public const TIMEOUT_CLOUD = 60;
    public const TIMEOUT_OLLAMA = 180;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly AiProviders $providers,
        private readonly ReceiptService $receipts,
        private readonly HttpTransport $http,
        private readonly Lang $lang,
        private readonly LoggerInterface $log,
    ) {
    }

    /**
     * @return array{date: ?string, positions: list<array{item: string, amount_cents: int}>, total_cents: ?int, provider: string}
     */
    public function recognize(string $receiptId): array
    {
        $s = $this->settings->all();
        if (($s['recognition_mode'] ?? null) !== 'ai') {
            throw new ApiException(409, 'recognition_disabled');
        }
        $provider = is_string($s['ai_provider'] ?? null) && $this->providers->has($s['ai_provider']) ? $s['ai_provider'] : null;
        $key = is_string($s['ai_key'] ?? null) ? $s['ai_key'] : null;
        $base = is_string($s['ai_base_url'] ?? null) ? $s['ai_base_url'] : null;
        if ($provider === null || ($this->providers->needsKey($provider) && ($key === null || $key === ''))
            || ($this->providers->needsBaseUrl($provider) && $base === null)) {
            throw new ApiException(409, 'recognition_disabled', 'recognition.err_not_configured');
        }
        $row = $this->receipts->find($receiptId);
        $path = $this->receipts->filePath($receiptId);
        if ($row === null || !is_file($path)) {
            throw ApiException::notFound();
        }
        $bytes = (string) file_get_contents($path);
        $model = $this->model($provider, $s['ai_model'] ?? null);

        try {
            $text = $this->call($provider, $model, $key, $base, Prompt::RECEIPT, ['mime' => (string) $row['mime'], 'b64' => base64_encode($bytes)], false);
            $result = ResultParser::parse($text);
        } catch (ProviderException $e) {
            $this->log->warning('recognition failed: {why}', ['provider' => $provider, 'why' => $e->getMessage()]);
            throw new ApiException(502, 'provider_error', 'recognition.err_' . $e->kind);
        }
        return $result + ['provider' => $provider];
    }

    /**
     * Verbindungstest; fehlende Angaben werden aus den gespeicherten Einstellungen ergaenzt.
     * @param array<string, mixed> $in
     * @return array{ok: bool, message: string}
     */
    public function test(array $in): array
    {
        $s = $this->settings->all();
        $provider = $in['ai_provider'] ?? $s['ai_provider'] ?? null;
        if (!is_string($provider) || !$this->providers->has($provider)) {
            throw ApiException::validation(['ai_provider' => $this->lang->t('validation.invalid_choice')]);
        }
        $str = static fn ($v): ?string => is_string($v) && trim($v) !== '' ? trim($v) : null;
        $key = $str($in['ai_key'] ?? null) ?? $str($s['ai_key'] ?? null);
        $base = $str($in['ai_base_url'] ?? null) ?? $str($s['ai_base_url'] ?? null);
        $base = $base !== null ? rtrim($base, '/') : null;
        if ($base !== null && !preg_match('#^https?://[^\s/]+#i', $base)) {
            throw ApiException::validation(['ai_base_url' => $this->lang->t('validation.url')]);
        }
        $name = (string) ($this->providers->all()[array_search($provider, $this->providers->ids(), true)]['name']);
        if ($this->providers->needsKey($provider) && $key === null) {
            return ['ok' => false, 'message' => $this->lang->t('recognition.test_no_key', ['provider' => $name])];
        }
        if ($this->providers->needsBaseUrl($provider) && $base === null) {
            return ['ok' => false, 'message' => $this->lang->t('recognition.test_no_url')];
        }
        $model = $this->model($provider, $str($in['ai_model'] ?? null) ?? $str($s['ai_model'] ?? null));
        try {
            $this->call($provider, $model, $key, $base, Prompt::PROBE, null, true);
        } catch (ProviderException $e) {
            $this->log->info('recognition test failed: {why}', ['provider' => $provider, 'why' => $e->getMessage()]);
            return ['ok' => false, 'message' => $this->lang->t('recognition.err_' . $e->kind)];
        }
        return ['ok' => true, 'message' => $this->lang->t('recognition.test_ok', ['provider' => $name, 'model' => $model])];
    }

    private function model(string $provider, mixed $configured): string
    {
        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }
        return $this->providers->defaultModel($provider) ?? '';
    }

    /** Eine Anfrage; liefert den Antworttext des Modells. @throws ProviderException */
    private function call(string $provider, string $model, ?string $key, ?string $base, string $prompt, ?array $image, bool $probe): string
    {
        @set_time_limit(self::TIMEOUT_OLLAMA + 30);
        $req = Providers::buildRequest($provider, $model, $key, $base, $prompt, $image, $probe);
        $timeout = $provider === 'ollama' ? self::TIMEOUT_OLLAMA : self::TIMEOUT_CLOUD;
        $res = $this->http->post($req['url'], $req['headers'], $req['body'], $timeout);
        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw new ProviderException(self::kindForStatus($res['status'], $res['body']), $res['status']);
        }
        $text = Providers::extractText($provider, json_decode($res['body'], true));
        if ($text === null) {
            throw new ProviderException('unreadable', $res['status']);
        }
        return $text;
    }

    private static function kindForStatus(int $status, string $body): string
    {
        $b = strtolower(substr($body, 0, 2000));
        $keyHint = str_contains($b, 'api key') || str_contains($b, 'api_key') || str_contains($b, 'x-api-key')
            || str_contains($b, 'unauthorized') || str_contains($b, 'authentication') || str_contains($b, 'invalid_api');
        if ($status === 401 || $status === 403 || ($status === 400 && $keyHint)) {
            return 'key_invalid';
        }
        if ($status === 404 || (($status === 400 || $status === 422) && str_contains($b, 'model'))) {
            return 'model_unknown';
        }
        if ($status === 429) {
            return 'rate_limit';
        }
        if ($status === 408 || $status === 504) {
            return 'timeout';
        }
        return $status >= 500 ? 'server' : 'failed';
    }
}
