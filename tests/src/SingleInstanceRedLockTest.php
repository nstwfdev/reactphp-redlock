<?php


namespace Nstwf\Redlock;


use Clue\React\Redis\RedisClient;
use Nstwf\Redlock\Exceptions\FailedToAcquireException;
use Nstwf\Redlock\Exceptions\FailedToReleaseException;
use Nstwf\Redlock\Lock\Lock;
use Nstwf\Redlock\Lua\LuaScripts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;


use function React\Async\await;
use function React\Promise\resolve;


class SingleInstanceRedLockTest extends TestCase
{
    public function testAcquireWillSetRedisKey(): void
    {
        $redisClient = $this
            ->getMockBuilder(RedisClient::class)
            ->disableOriginalConstructor()
            ->getMock();

        $redisClient->expects($this->once())
            ->method('__call')
            ->with('set', ['mykey', 'random_id', 'NX', 'PX', 60000])
            ->willReturn(resolve(Reply::ACQUIRE_SUCCESS));

        $redlock = new SingleInstanceRedLock($redisClient, true);
        $lock = await($redlock->acquire('mykey', 60, 'random_id'));

        $this->assertEquals(new Lock('mykey', 60, 'random_id'), $lock);
    }

    #[DataProvider('releaseWaitForRequireDataProvider')]
    public function testReleaseWillEvalLuaScript(bool $waitForAcquire, string $evalResult): void
    {
        $redisClient = $this
            ->getMockBuilder(RedisClient::class)
            ->disableOriginalConstructor()
            ->getMock();

        $redisClient->expects($this->exactly(2))
            ->method('__call')
            ->willReturnCallback(function (...$args) use ($evalResult) {
                static $i = 0;

                $expected = [
                    ['set', ['mykey', 'random_id', 'NX', 'PX', 60000]],
                    ['eval', [LuaScripts::releaseLock(), 1, 'mykey', 'random_id']],
                ];

                $this->assertSame($expected[$i][0], $args[0]);
                $this->assertSame($expected[$i][1], $args[1]);
                $i++;

                $replies = [
                    resolve(Reply::ACQUIRE_SUCCESS),
                    resolve($evalResult),
                ];

                return $replies[$i - 1];
            });

        $redlock = new SingleInstanceRedLock($redisClient, $waitForAcquire);
        $lock = await($redlock->acquire('mykey', 60, 'random_id'));
        await($redlock->release($lock));
    }

    public static function releaseWaitForRequireDataProvider(): array
    {
        return [
            'waitForAcquire=true and eval result=1'  => [true, Reply::RELEASE_SUCCESS],
            'waitForAcquire=false and eval result=1' => [false, Reply::RELEASE_SUCCESS],
        ];
    }

    public function testAcquireTwiceWithoutWaitForAcquireWillThrowException(): void
    {
        $redisClient = $this
            ->getMockBuilder(RedisClient::class)
            ->disableOriginalConstructor()
            ->getMock();

        $redisClient->expects($this->exactly(2))
            ->method('__call')
            ->willReturnCallback(function (...$args) {
                static $i = 0;

                $expected = [
                    ['set', ['mykey', 'random_id', 'NX', 'PX', 60000]],
                    ['set', ['mykey', 'random_id_2', 'NX', 'PX', 60000]],
                ];

                $this->assertSame($expected[$i][0], $args[0]);
                $this->assertSame($expected[$i][1], $args[1]);
                $i++;

                $replies = [
                    resolve(Reply::ACQUIRE_SUCCESS),
                    resolve(Reply::ACQUIRE_ERROR),
                ];

                return $replies[$i - 1];
            });

        $redlock = new SingleInstanceRedLock($redisClient, false);

        $this->expectException(FailedToAcquireException::class);

        await($redlock->acquire('mykey', 60, 'random_id'));
        await($redlock->acquire('mykey', 60, 'random_id_2'));
    }

    public function testSeveralAcquireDifferentKeysWillReturnLocks(): void
    {
        $redisClient = $this
            ->getMockBuilder(RedisClient::class)
            ->disableOriginalConstructor()
            ->getMock();

        $redisClient->expects($this->exactly(2))
            ->method('__call')
            ->willReturnCallback(function ($command, $arguments) {
                static $i = 0;

                if ($i === 0) {
                    $this->assertSame('set', $command);
                    $this->assertSame(['mykey', 'random_id', 'NX', 'PX', 60000], $arguments);
                    $i++;
                    return resolve(Reply::ACQUIRE_SUCCESS);
                }

                if ($i === 1) {
                    $this->assertSame('set', $command);
                    $this->assertSame(['mykey_2', 'random_id_2', 'NX', 'PX', 60000], $arguments);
                    $i++;
                    return resolve(Reply::ACQUIRE_SUCCESS);
                }

                $this->fail('Unexpected call to executeCommand');
            });

        $redlock = new SingleInstanceRedLock($redisClient, false);

        $lock1 = await($redlock->acquire('mykey', 60, 'random_id'));
        $this->assertEquals(new Lock('mykey', 60, 'random_id'), $lock1);

        $lock2 = await($redlock->acquire('mykey_2', 60, 'random_id_2'));
        $this->assertEquals(new Lock('mykey_2', 60, 'random_id_2'), $lock2);
    }

    public function testAcquireTwiceWithWaitForAcquireWillRealiseLock(): void
    {
        $redisClient = $this
            ->getMockBuilder(RedisClient::class)
            ->disableOriginalConstructor()
            ->getMock();

        $redisClient->expects($this->exactly(4))
            ->method('__call')
            ->willReturnCallback(function (...$args) {
                static $i = 0;

                $expected = [
                    ['set', ['mykey', 'random_id', 'NX', 'PX', 60000]],
                    ['set', ['mykey', 'random_id_2', 'NX', 'PX', 60000]],
                    ['eval', [LuaScripts::releaseLock(), 1, 'mykey', 'random_id']],
                    ['set', ['mykey', 'random_id_2', 'NX', 'PX', 60000]],
                ];

                $this->assertSame($expected[$i][0], $args[0]);       // команда: set или eval
                $this->assertSame($expected[$i][1], $args[1]);       // аргументы команды
                $i++;

                $replies = [
                    resolve(Reply::ACQUIRE_SUCCESS),
                    resolve(Reply::ACQUIRE_ERROR),
                    resolve(Reply::RELEASE_SUCCESS),
                    resolve(Reply::ACQUIRE_SUCCESS),
                ];

                return $replies[$i - 1];
            });

        $redlock = new SingleInstanceRedLock($redisClient, true);

        $lock1 = await($redlock->acquire('mykey', 60, 'random_id'));
        $this->assertEquals(new Lock('mykey', 60, 'random_id'), $lock1);

        $lock2Promise = $redlock->acquire('mykey', 60, 'random_id_2');

        await($redlock->release($lock1));

        $lock2 = await($lock2Promise);
        $this->assertEquals(new Lock('mykey', 60, 'random_id_2'), $lock2);
    }

    public function testReleaseReplyErrorWillThrowException()
    {
        $redisClient = $this
            ->getMockBuilder(RedisClient::class)
            ->disableOriginalConstructor()
            ->getMock();

        $redisClient->expects($this->exactly(2))
            ->method('__call')
            ->willReturnCallback(function (...$args) {
                static $i = 0;

                $expected = [
                    ['set', ['mykey', 'random_id', 'NX', 'PX', 60000]],
                    ['eval', [LuaScripts::releaseLock(), 1, 'mykey', 'random_id']],
                ];

                $this->assertSame($expected[$i][0], $args[0]);
                $this->assertSame($expected[$i][1], $args[1]);
                $i++;

                $replies = [
                    resolve(Reply::ACQUIRE_SUCCESS),
                    resolve(Reply::RELEASE_ERROR),
                ];

                return $replies[$i - 1];
            });

        $redlock = new SingleInstanceRedLock($redisClient, true);

        $lock1 = await($redlock->acquire('mykey', 60, 'random_id'));
        $this->assertEquals(new Lock('mykey', 60, 'random_id'), $lock1);

        $this->expectException(FailedToReleaseException::class);
        await($redlock->release($lock1));
    }

    public function testAcquireTwiceWithErrorSecondAcquireDoesNotReturnLock()
    {
        $redisClient = $this
            ->getMockBuilder(RedisClient::class)
            ->disableOriginalConstructor()
            ->getMock();

        $redisClient->expects($this->exactly(4))
            ->method('__call')
            ->willReturnCallback(function (...$args) {
                static $i = 0;

                $expected = [
                    ['set', ['mykey', 'random_id', 'NX', 'PX', 60000]],
                    ['set', ['mykey', 'random_id_2', 'NX', 'PX', 60000]],
                    ['eval', [LuaScripts::releaseLock(), 1, 'mykey', 'random_id']],
                    ['set', ['mykey', 'random_id_2', 'NX', 'PX', 60000]],
                ];

                $this->assertSame($expected[$i][0], $args[0]);
                $this->assertSame($expected[$i][1], $args[1]);
                $i++;

                $replies = [
                    resolve(Reply::ACQUIRE_SUCCESS),
                    resolve(Reply::ACQUIRE_ERROR),
                    resolve(Reply::RELEASE_SUCCESS),
                    resolve(Reply::ACQUIRE_ERROR),
                ];

                return $replies[$i - 1];
            });

        $redlock = new SingleInstanceRedLock($redisClient, true);

        $lock1 = await($redlock->acquire('mykey', 60, 'random_id'));
        $this->assertEquals(new Lock('mykey', 60, 'random_id'), $lock1);

        $promise = $redlock->acquire('mykey', 60, 'random_id_2');

        await($redlock->release($lock1));

        $this->expectException(\Error::class);
        await($promise);
    }
}
