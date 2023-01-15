<?php


declare(strict_types=1);


namespace Nstwf\Redlock;


use Clue\React\Redis\RedisClient;
use Nstwf\Redlock\Exceptions\FailedToAcquireException;
use Nstwf\Redlock\Exceptions\FailedToReleaseException;
use Nstwf\Redlock\Lock\Lock;
use Nstwf\Redlock\Lock\LockDeferred;
use Nstwf\Redlock\Lua\LuaScripts;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;


use function React\Promise\reject;
use function React\Promise\resolve;


final class SingleInstanceRedLock implements RedLockInterface
{
    /** @var array<string, LockDeferred[]> */
    private array $queue = [];

    public function __construct(
        private RedisClient $redisClient,
        private bool $waitForAcquire = true,
    ) {
    }

    public function acquire(string $key, float $ttl = 60, ?string $id = null): PromiseInterface
    {
        $id ??= Lock::generateId();

        return $this
            ->tryAcquire($key, $ttl, $id);
    }

    public function release(Lock $lock): PromiseInterface
    {
        return $this
            ->redisClient
            ->eval(LuaScripts::releaseLock(), 1, $lock->getKey(), $lock->getId())
            ->then(function (?string $response) use ($lock) {
                if ($response !== '1') {
                    return reject(new FailedToReleaseException());
                }

                if (!$this->waitForAcquire) {
                    return resolve();
                }

                if (!array_key_exists($lock->getKey(), $this->queue) || count($this->queue[$lock->getKey()]) === 0) {
                    return resolve();
                }

                /** @var LockDeferred $queued */
                $queued = array_shift($this->queue[$lock->getKey()]);
                $deferred = $queued->getDeferred();

                return $this
                    ->tryAcquire($queued->getKey(), $queued->getTtl(), $queued->getId(), true)
                    ->then(fn(Lock $lock) => $deferred->resolve($lock))
                    ->otherwise(fn() => resolve());
            });
    }

    private function tryAcquire(string $key, float $ttl, string $id, bool $queued = false): PromiseInterface
    {
        return $this
            ->setKey($key, $id, $ttl)
            ->then(function (?string $response) use ($key, $ttl, $id, $queued) {
                if ($response === 'OK') {
                    return resolve(new Lock($key, $ttl, $id));
                }

                if (!$this->waitForAcquire) {
                    return reject(new FailedToAcquireException());
                }

                if ($queued) {
                    return reject(new FailedToAcquireException());
                }

                $deferred = new Deferred();

                $this->queue[$key][] = new LockDeferred($key, $ttl, $id, $deferred);

                return $deferred->promise();
            });
    }

    private function setKey(string $key, string $id, float $ttl): PromiseInterface
    {
        return $this
            ->redisClient
            ->set($key, $id, 'NX', 'PX', (int)round($ttl * 1000));
    }
}
