<?php


declare(strict_types=1);


namespace Nstwf\Redlock;


use Clue\React\Redis\RedisClient;
use Nstwf\Redlock\Exceptions\FailedToAcquireException;
use Nstwf\Redlock\Exceptions\FailedToReleaseException;
use Nstwf\Redlock\Lock\Lock;
use Nstwf\Redlock\Lua\LuaScripts;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;


use function React\Promise\reject;
use function React\Promise\resolve;


final class MultiInstanceRedLock implements RedLockInterface
{
    public function __construct(
        private RedisClient $redisClient,
        private ?LoopInterface $loop = null,
        private bool $waitForAcquire = true,
        private int $retryLimit = 0,
        private float $retryAfter = 0.001,
    ) {
    }

    public function acquire(string $key, float $ttl = 60, ?string $id = null): PromiseInterface
    {
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
                    return resolve();
                }

                return reject(new FailedToReleaseException());
            });
    }

    private function tryAcquire(string $key, float $ttl, ?string $id = null): PromiseInterface
    {
        $id ??= Lock::generateId();

        return $this
            ->setKey($key, $id, $ttl)
            ->then(function (?string $response) use ($key, $ttl, $id) {
                if ($response === 'OK') {
                    return resolve(new Lock($key, $ttl, $id));
                }

                if (!$this->waitForAcquire) {
                    return reject(new FailedToAcquireException());
                }

                $deferred = new Deferred();

                $retryCount = 0;

                $this
                    ->loop
                    ->addPeriodicTimer($this->retryAfter, function (TimerInterface $timer) use ($key, $ttl, $deferred, &$retryCount) {
                        $retryCount++;

                        return $this
                            ->tryAcquire($key, $ttl)
                            ->then(function (Lock $lock) use ($timer, $deferred) {
                                $this->loop->cancelTimer($timer);
                                $deferred->resolve($lock);
                            })
                            ->otherwise(function () use ($retryCount) {
                                if ($this->retryLimit > 0 && $this->retryLimit < $retryCount) {
                                    return reject(new FailedToAcquireException());
                                }

                                return resolve();
                            });
                    });

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
