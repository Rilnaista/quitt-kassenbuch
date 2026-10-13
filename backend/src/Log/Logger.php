<?php

declare(strict_types=1);

namespace Quitt\Log;

use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;
use Quitt\Support\Clock;
use Quitt\Support\SystemClock;
use Quitt\Support\Time;

/**
 * PSR-3-Logger: JSON-Lines in <dir>/quitt-YYYY-MM.log, hoechstens $maxFiles Dateien.
 * Zeile: {"ts","level","channel","message","context"}. Geheimnisse werden maskiert.
 * Schreibfehler werden verschluckt (Logging darf nie einen Request abbrechen).
 */
final class Logger extends AbstractLogger
{
    public const LEVELS = [
        LogLevel::DEBUG => 0, LogLevel::INFO => 1, LogLevel::NOTICE => 2, LogLevel::WARNING => 3,
        LogLevel::ERROR => 4, LogLevel::CRITICAL => 5, LogLevel::ALERT => 6, LogLevel::EMERGENCY => 7,
    ];

    private readonly Clock $clock;

    public function __construct(
        private readonly string $dir,
        private readonly string $channel = 'app',
        private readonly string $minLevel = LogLevel::INFO,
        private readonly int $maxFiles = 6,
        ?Clock $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
    }

    public function withChannel(string $channel): self
    {
        return new self($this->dir, $channel, $this->minLevel, $this->maxFiles, $this->clock);
    }

    public function dir(): string
    {
        return $this->dir;
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $level = strtolower((string) $level);
        if (!isset(self::LEVELS[$level])) {
            throw new InvalidArgumentException('Unbekannte Log-Stufe: ' . $level);
        }
        if (self::LEVELS[$level] < (self::LEVELS[$this->minLevel] ?? 1)) {
            return;
        }
        $now = $this->clock->now();
        $context = $this->normalize($context);
        $line = [
            'ts' => Time::iso($now),
            'level' => $level,
            'channel' => $this->channel,
            'message' => Masker::maskText($this->interpolate((string) $message, $context)),
            'context' => $context === [] ? new \stdClass() : $context,
        ];
        try {
            $json = json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if ($json === false) {
                return;
            }
            if (!is_dir($this->dir)) {
                @mkdir($this->dir, 0775, true);
            }
            $file = $this->dir . '/quitt-' . $now->format('Y-m') . '.log';
            $isNew = !is_file($file);
            @file_put_contents($file, $json . "\n", FILE_APPEND | LOCK_EX);
            if ($isNew) {
                $this->rotate();
            }
        } catch (\Throwable) {
            // bewusst still
        }
    }

    /** @return string[] Logdateien, neueste zuletzt */
    public function files(): array
    {
        $files = glob($this->dir . '/quitt-*.log') ?: [];
        sort($files, SORT_STRING);
        return $files;
    }

    private function rotate(): void
    {
        $files = $this->files();
        while (count($files) > $this->maxFiles) {
            @unlink(array_shift($files));
        }
    }

    private function normalize(array $context): array
    {
        if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
            $e = $context['exception'];
            $context['exception'] = [
                'class' => $e::class,
                'message' => $e->getMessage(),
                'file' => basename($e->getFile()) . ':' . $e->getLine(),
            ];
        }
        $context = array_map(fn ($v) => $this->scalarize($v), $context);
        return Masker::mask($context);
    }

    private function scalarize(mixed $v): mixed
    {
        if (is_array($v)) {
            return array_map(fn ($x) => $this->scalarize($x), $v);
        }
        if ($v instanceof \DateTimeInterface) {
            return $v->format(DATE_ATOM);
        }
        if ($v instanceof \Stringable) {
            return (string) $v;
        }
        if (is_object($v)) {
            return '[' . $v::class . ']';
        }
        if (is_resource($v)) {
            return '[resource]';
        }
        return $v;
    }

    private function interpolate(string $message, array $context): string
    {
        if (!str_contains($message, '{')) {
            return $message;
        }
        $replace = [];
        foreach ($context as $k => $v) {
            if (is_scalar($v) || $v === null) {
                $replace['{' . $k . '}'] = (string) $v;
            }
        }
        return strtr($message, $replace);
    }
}
