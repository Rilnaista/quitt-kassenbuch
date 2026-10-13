<?php

declare(strict_types=1);

namespace Quitt\App;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Minimaler PSR-11-Container mit lazy Singletons.
 * Dienste werden per set(id, factory) registriert; die Factory erhaelt den Container.
 * Controller muessen NICHT registriert werden: Slim instanziiert sie mit new X($container).
 */
final class Container implements ContainerInterface
{
    /** @var array<string, callable> */
    private array $factories = [];
    /** @var array<string, mixed> */
    private array $instances = [];

    public function set(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function instance(string $id, mixed $value): void
    {
        $this->instances[$id] = $value;
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->instances) || isset($this->factories[$id]);
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }
        if (!isset($this->factories[$id])) {
            throw new class ("Dienst nicht registriert: $id") extends \RuntimeException implements NotFoundExceptionInterface {
            };
        }
        return $this->instances[$id] = ($this->factories[$id])($this);
    }
}
