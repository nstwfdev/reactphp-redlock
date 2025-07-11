<?php

namespace Nstwf\Redlock;

use Nstwf\Redlock\Lock\Lock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;


class LockTest extends TestCase
{
    public function testProperties(): void
    {
        $lock = new Lock('mykey', 60.01, 50);

        $this->assertEquals('mykey', $lock->getKey());
        $this->assertEquals(50, $lock->getId());
        $this->assertEquals(60.01, $lock->getTtl());
    }

    #[DataProvider('uniqueGenerateDataProvider')]
    public function testLockGenerateUniqueId(int $count): void
    {
        $ids = [];

        for ($i = 0; $i < $count; $i++) {
            $ids[] = Lock::generateId();
        }

        $this->assertCount($count, array_unique($ids));
    }

    public static function uniqueGenerateDataProvider(): array
    {
        return [
            10 => [10],
            100 => [100],
        ];
    }
}
