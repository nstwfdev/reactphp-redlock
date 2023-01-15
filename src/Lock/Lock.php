<?php


declare(strict_types=1);


namespace Nstwf\Redlock\Lock;


final class Lock
{
    public const KEY_PREFIX = ':lock';

    public function __construct(
        private string $key,
        private float $ttl,
        private string $id
    ) {
    }

    public static function generateId(): string
    {
        return bin2hex(random_bytes(20));
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
}
