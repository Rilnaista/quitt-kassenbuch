<?php

declare(strict_types=1);

namespace Quitt\Recognition;

/**
 * Fehler beim KI-Dienst. kind -> Sprachschluessel "recognition.err_<kind>":
 * key_invalid, model_unknown, unreachable, timeout, unreadable, rate_limit, server, failed.
 * Enthaelt bewusst nie Schluessel oder Antwortinhalte.
 */
final class ProviderException extends \RuntimeException
{
    public function __construct(public readonly string $kind, public readonly int $httpStatus = 0)
    {
        parent::__construct('provider error: ' . $kind . ($httpStatus > 0 ? ' (HTTP ' . $httpStatus . ')' : ''));
    }
}
