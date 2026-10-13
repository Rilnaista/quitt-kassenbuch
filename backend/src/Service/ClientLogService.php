<?php

declare(strict_types=1);

namespace Quitt\Service;

use Psr\Log\LoggerInterface;
use Quitt\App\Config;
use Quitt\Http\ApiException;
use Quitt\Support\Clock;

/**
 * Nimmt Meldungen der App ins gemeinsame Log auf (Kanal "client").
 * Drosselung pro Installation: hoechstens client_log_per_minute Eintraege je Minute;
 * Ueberschuss wird still verworfen (die App soll nie wegen des Loggens Fehler zeigen).
 * Zaehler in einer kleinen Datei im Log-Ordner (funktioniert auch vor der Installation).
 */
final class ClientLogService
{
    public const LEVELS = ['error', 'warning', 'info'];
    private const MAX_MESSAGE = 2000;
    private const MAX_CONTEXT_BYTES = 8192;

    public function __construct(
        private readonly LoggerInterface $log,
        private readonly Clock $clock,
        private readonly Config $config,
    ) {
    }

    /** @return bool false = gedrosselt (verworfen) */
    public function accept(array $body): bool
    {
        $errors = [];
        $level = $body['level'] ?? null;
        if (!in_array($level, self::LEVELS, true)) {
            $errors['level'] = 'validation.invalid_choice';
        }
        $message = $body['message'] ?? null;
        if (!is_string($message) || trim($message) === '') {
            $errors['message'] = 'validation.required';
        }
        $context = $body['context'] ?? [];
        if ($context === null) {
            $context = [];
        }
        if (!is_array($context)) {
            $errors['context'] = 'validation.object';
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
        if (!$this->allow()) {
            return false;
        }
        $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
        if (strlen($json) > self::MAX_CONTEXT_BYTES) {
            $context = ['truncated' => true, 'excerpt' => mb_strcut($json, 0, self::MAX_CONTEXT_BYTES)];
        }
        $this->log->log($level, mb_substr(trim($message), 0, self::MAX_MESSAGE), $context);
        return true;
    }

    private function allow(): bool
    {
        $limit = max(1, (int) $this->config->get('client_log_per_minute', 30));
        $file = $this->config->path('logs/.client-throttle');
        $minute = intdiv($this->clock->now()->getTimestamp(), 60);
        $fh = @fopen($file, 'c+');
        if ($fh === false) {
            return true;
        }
        try {
            flock($fh, LOCK_EX);
            [$m, $n] = array_map('intval', array_pad(explode(':', (string) stream_get_contents($fh)), 2, 0));
            $n = $m === $minute ? $n + 1 : 1;
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, $minute . ':' . $n);
            return $n <= $limit;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
}
