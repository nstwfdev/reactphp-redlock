<?php


declare(strict_types=1);


namespace Nstwf\Redlock;


use Nstwf\Redlock\Lock\Lock;
use React\Promise\PromiseInterface;


interface RedLockInterface
{
    public function acquire(string $key, float $ttl = 60, ?string $id = null): PromiseInterface;

    public function release(Lock $lock): PromiseInterface;
}
