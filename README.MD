# ReactPHP Distributed Locks with Redis

[![CI](https://img.shields.io/github/actions/workflow/status/nstwfdev/reactphp-redlock/ci.yml?branch=master&label=ci&logo=github)](https://github.com/nstwfdev/reactphp-redlock/actions?query=workflow%3Aci+branch%3Amaster)
[![codecov](https://codecov.io/gh/nstwfdev/reactphp-redlock/branch/master/graph/badge.svg?token=9YL9FSM4RV)](https://codecov.io/gh/nstwfdev/reactphp-redlock)
[![Packagist Version](https://img.shields.io/packagist/v/nstwf/reactphp-redlock?logo=packagist)](https://packagist.org/packages/nstwf/reactphp-redlock)

Reactphp redlock realization. [Read about it](https://redis.io/docs/manual/patterns/distributed-locks/)

**Table of contents**

* [Quickstart example](#quickstart-example)
* [Usage](#usage)
    * [PoolInterface](#poolinterface)
        * [Options](#options)
        * [getConnection()](#getconnection)
        * [releaseConnection()](#releaseconnection)
        * [query()](#query)
        * [transaction()](#transaction)
* [Install](#install)
* [Tests](#tests)
* [License](#license)

## Quickstart example

```php
$uri = 'localhost:6379';

$redisClient = new \Clue\React\Redis\RedisClient($uri);
$redlock = new \Nstwf\Redlock\SingleInstanceRedLock(new \Clue\React\Redis\RedisClient($uri));

$redlock
    ->acquire("user_with_id1", 3600, 'random_id')
    ->then(function (\Nstwf\Redlock\Lock\Lock $lock) use ($redlock, $redisClient) {
        return $redisClient
                ->set('key', 'value')
                ->then(fn() => $redlock->release($lock));
    });
```

## Usage

### RedlockInterface

The main role of `RedlockInterface` - managing distributed locks

#### acquire

The `acquire(string $key, float $ttl = 60, ?string $id = null): PromiseInterface` method can be used to acquire the lock.

Watch options in [SingleInstanceRedlock](#singleinstanceredlock) and [MultiInstanceRedlock](#multiinstanceredlock) to determine behavior if lock had already set

```php
$redlock->acquire("user_with_id1", 3600, 'random_id')
        ->then(function (\Nstwf\Redlock\Lock\Lock $lock) {
            // do something
        })
        ->otherwise(function (\Throwable $throwable) {
            // handle
        })
```

#### release

The `release(Lock $lock): PromiseInterface` method can be used to realise the lock.

```php
$redlock->release($lock)
        ->then(function () {
            // release succeed
        })
        ->otherwise(function (\Throwable $throwable) {
            // handle
        })
```

### SingleInstanceRedlock

Single instance redlock use in-memory queue for concurrency acquire. Do not use redis

#### Options

- `waitForAcquire: bool`, set up the behavior while key had already locked and user call `acquire` method. If set to `false` - throws an exception, otherwise return a promise with lock. (Default: `true`)

```php
$redisClient = new \Clue\React\Redis\RedisClient('localhost:6379');
$redlock = new \Nstwf\Redlock\SingleInstanceRedLock($redisClient, true);
```

### MultiInstanceRedlock

Single instance redlock use loop periodic timer, for concurrency acquire. Use redis to check actual lock using periodic timer

#### Options

- `waitForAcquire: bool`, set up the behavior while key had already locked and user call `acquire` method. If set to `false` - throws an exception, otherwise return a promise with lock. (Default: `true`)
- `retryLimit: int`, the maximum number of retries. `0` - for unlimited. (Default: `0`)
- `retryAfter: float`, the interval between retries in seconds. (Default: `0.001`)

```php
$redisClient = new \Clue\React\Redis\RedisClient('localhost:6379');
$redlock = new \Nstwf\Redlock\MultiInstanceRedLock($redisClient, true, 0, 0.001);
```

## Install

The recommended way to install this library is [through Composer](https://getcomposer.org).
[New to Composer?](https://getcomposer.org/doc/00-intro.md)

This project follows [SemVer](https://semver.org/).
This will install the latest supported version:

```bash
composer require nstwf/reactphp-redlock
```

See also the [CHANGELOG](docs/CHANGELOG.md) for details about version upgrades.

It's *highly recommended to use PHP 8+* * for this project.

## Tests

To run the test suite, you first need to clone this repo and then install all
dependencies [through Composer](https://getcomposer.org):

```bash
composer install
```

To run the test suite, go to the project root and run:

```bash
vendor/bin/phpunit
```

## License

MIT, see [LICENSE file](LICENSE).
