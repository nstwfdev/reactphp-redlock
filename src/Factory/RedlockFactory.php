<?php


declare(strict_types=1);


namespace Nstwf\Redlock\Factory;


use Clue\React\Redis\RedisClient;
use Nstwf\Redlock\MultiInstanceRedLock;
use Nstwf\Redlock\RedLockInterface;
use Nstwf\Redlock\SingleInstanceRedLock;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;


final class RedlockFactory implements RedlockFactoryInterface
{
    private string $uri;
    private LoopInterface $loop;
    private ?RedisClient $client;

    public function __construct(
        #[\SensitiveParameter]
        string $uri,
        ?LoopInterface $loop = null,
    ) {
        $this->uri = $uri;
        $this->loop = $loop ?? Loop::get();
    }

    public function singleInstance(bool $waitForAcquire = false): RedLockInterface
    {
        return new SingleInstanceRedLock(
            $this->createClient(),
            $waitForAcquire
        );
    }

    public function multiInstance(bool $waitForAcquire = true, int $retryLimit = 0, float $retryAfter = 0.001): RedLockInterface
    {
        return new MultiInstanceRedLock(
            $this->createClient(),
            $this->loop,
            $waitForAcquire,
            $retryLimit,
            $retryAfter
        );
    }

    private function createClient(): RedisClient
    {
        return new RedisClient($this->uri);
    }
}
