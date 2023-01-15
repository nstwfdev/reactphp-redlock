<?php


namespace Nstwf\Redlock;


use Clue\React\Redis\RedisClient;
use Nstwf\Redlock\Exceptions\FailedToAcquireException;
use Nstwf\Redlock\Exceptions\FailedToReleaseException;
use Nstwf\Redlock\Lock\Lock;
use Nstwf\Redlock\Lua\LuaScripts;
use PHPUnit\Framework\TestCase;


use function React\Async\await;
use function React\Promise\resolve;


class SingleInstanceRedLockTest extends TestCase
{
    public function testAcquireWillSetRedisKey()
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

    /**
     * @dataProvider releaseWaitForRequireDataProvider
     */
    public function testReleaseWillEvalLuaScript(bool $waitForAcquire, string $evalResult)
    {
        $redisClient = $this
            ->getMockBuilder(RedisClient::class)
            ->disableOriginalConstructor()
            ->getMock();

        $redisClient->expects($this->exactly(2))
            ->method('__call')
            ->withConsecutive(
                ['set', ['mykey', 'random_id', 'NX', 'PX', 60000]],
                ['eval', [LuaScripts::releaseLock(), 1, 'mykey', 'random_id']]
            )
            ->willReturnOnConsecutiveCalls(
                resolve(Reply::ACQUIRE_SUCCESS),
                resolve($evalResult),
            );

        $redlock = new SingleInstanceRedLock($redisClient, $waitForAcquire);
        $lock = await($redlock->acquire('mykey', 60, 'random_id'));
        await($redlock->release($lock));
    }

    private function releaseWaitForRequireDataProvider(): array
    {
        return [
            'waitForAcquire=true and eval result=1'  => [true, Reply::RELEASE_SUCCESS],
            'waitForAcquire=false and eval result=1' => [false, Reply::RELEASE_SUCCESS],
        ];
    }

    public function testAcquireTwiceWithoutWaitForAcquireWillThrowException()
    {
        $redisClient = $this
            ->getMockBuilder(RedisClient::class)
            ->disableOriginalConstructor()
            ->getMock();

        $redisClient->expects($this->exactly(2))
            ->method('__call')
            ->withConsecutive(
                ['set', ['mykey', 'random_id', 'NX', 'PX', 60000]],
                ['set', ['mykey', 'random_id_2', 'NX', 'PX', 60000]],
            )
            ->willReturnOnConsecutiveCalls(
                resolve(Reply::ACQUIRE_SUCCESS),
                resolve(Reply::ACQUIRE_ERROR),
            );

        $redlock = new SingleInstanceRedLock($redisClient, false);

        $this->expectException(FailedToAcquireException::class);

        await($redlock->acquire('mykey', 60, 'random_id'));
        await($redlock->acquire('mykey', 60, 'random_id_2'));
    }

    public function testSeveralAcquireDifferentKeysWillReturnLocks()
    {
        $redisClient = $this
            ->getMockBuilder(RedisClient::class)
            ->disableOriginalConstructor()
            ->getMock();

        $redisClient->expects($this->exactly(2))
            ->method('__call')
            ->withConsecutive(
                ['set', ['mykey', 'random_id', 'NX', 'PX', 60000]],
                ['set', ['mykey_2', 'random_id_2', 'NX', 'PX', 60000]],
            )
            ->willReturnOnConsecutiveCalls(
                resolve(Reply::ACQUIRE_SUCCESS),
                resolve(Reply::ACQUIRE_SUCCESS),
            );

        $redlock = new SingleInstanceRedLock($redisClient, false);

        $lock1 = await($redlock->acquire('mykey', 60, 'random_id'));
        $this->assertEquals(new Lock('mykey', 60, 'random_id'), $lock1);

        $lock2 = await($redlock->acquire('mykey_2', 60, 'random_id_2'));
        $this->assertEquals(new Lock('mykey_2', 60, 'random_id_2'), $lock2);
    }

    public function testAcquireTwiceWithWaitForAcquireWillRealiseLock()
    {
        $redisClient = $this
            ->getMockBuilder(RedisClient::class)
            ->disableOriginalConstructor()
            ->getMock();

        $redisClient->expects($this->exactly(4))
            ->method('__call')
            ->withConsecutive(
                ['set', ['mykey', 'random_id', 'NX', 'PX', 60000]],
                ['set', ['mykey', 'random_id_2', 'NX', 'PX', 60000]],
                ['eval', [LuaScripts::releaseLock(), 1, 'mykey', 'random_id']],
                ['set', ['mykey', 'random_id_2', 'NX', 'PX', 60000]],
            )
            ->willReturnOnConsecutiveCalls(
                resolve(Reply::ACQUIRE_SUCCESS),
                resolve(Reply::ACQUIRE_ERROR),
                resolve(Reply::RELEASE_SUCCESS),
                resolve(Reply::ACQUIRE_SUCCESS),
            );

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
            ->withConsecutive(
                ['set', ['mykey', 'random_id', 'NX', 'PX', 60000]],
                ['eval', [LuaScripts::releaseLock(), 1, 'mykey', 'random_id']],
            )
            ->willReturnOnConsecutiveCalls(
                resolve(Reply::ACQUIRE_SUCCESS),
                resolve(Reply::RELEASE_ERROR),
            );

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
            ->withConsecutive(
                ['set', ['mykey', 'random_id', 'NX', 'PX', 60000]],
                ['set', ['mykey', 'random_id_2', 'NX', 'PX', 60000]],
                ['eval', [LuaScripts::releaseLock(), 1, 'mykey', 'random_id']],
                ['set', ['mykey', 'random_id_2', 'NX', 'PX', 60000]],
            )
            ->willReturnOnConsecutiveCalls(
                resolve(Reply::ACQUIRE_SUCCESS),
                resolve(Reply::ACQUIRE_ERROR),
                resolve(Reply::RELEASE_SUCCESS),
                resolve(Reply::ACQUIRE_ERROR),
            );

        $redlock = new SingleInstanceRedLock($redisClient, true);

        $lock1 = await($redlock->acquire('mykey', 60, 'random_id'));
        $this->assertEquals(new Lock('mykey', 60, 'random_id'), $lock1);

        $promise = $redlock->acquire('mykey', 60, 'random_id_2');

        await($redlock->release($lock1));

        $this->expectException(\Error::class);
        await($promise);
    }
}
