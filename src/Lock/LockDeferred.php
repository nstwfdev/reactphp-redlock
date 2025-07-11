<?php
declare(strict_types=1);

namespace Nstwf\Redlock\Lock;

use React\Promise\Deferred;

final class LockDeferred
{
    public function __construct(
        private string $key,
        private float $ttl,
        private string $id,
        private Deferred $deferred
    ) {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getTtl(): float
    {
        return $this->ttl;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getDeferred(): Deferred
    {
        return $this->deferred;
    }
}
