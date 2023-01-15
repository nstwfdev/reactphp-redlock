<?php


namespace Nstwf\Redlock;


use Nstwf\Redlock\Lock\Lock;
use PHPUnit\Framework\TestCase;


class LockTest extends TestCase
{
    /**
     * @dataProvider uniqueGenerateDataProvider
     */
    public function testLockGenerateUniqueId(int $count)
    {
        $ids = [];

        for ($i = 0; $i < $count; $i++) {
            $ids[] = Lock::generateId();
        }

        $this->assertCount($count, array_unique($ids));
    }

    private function uniqueGenerateDataProvider(): array
    {
        return [
            10  => [10],
            100 => [100],
        ];
    }
}
