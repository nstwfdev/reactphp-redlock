<?php


declare(strict_types=1);


namespace Nstwf\Redlock\Factory;


use Nstwf\Redlock\RedLockInterface;


interface RedlockFactoryInterface
{
    /**
     * Create lazy connection from original factory and wrap it
     *
     * ```php
     * $redlock = $factory->singleInstance(true);
     * ```
     *
     * @param bool $waitForAcquire
     *
     * @return RedLockInterface
     */
    public function singleInstance(bool $waitForAcquire = true): RedLockInterface;

    /**
     * Create lazy connection from original factory and wrap it
     *
     * ```php
     * $redlock = $factory->multiInstance(true, 0, 0.001);
     * ```
     *
     * @param bool  $waitForAcquire
     * @param int   $retryLimit
     * @param float $retryAfter
     *
     * @return RedLockInterface
     */
    public function multiInstance(bool $waitForAcquire = true, int $retryLimit = 0, float $retryAfter = 0.001): RedLockInterface;
}
