<?php

namespace Amp\Parallel\Test\Sync;

use Amp\Parallel\Context\Thread;
use Amp\Parallel\Sync\Channel;
use Amp\Parallel\Sync\Parcel;
use Amp\Parallel\Sync\ThreadedParcel;

/**
 * @requires extension pthreads
 */
class ThreadedParcelTest extends AbstractParcelTest
{
    protected function createParcel($value): Parcel
    {
        return new ThreadedParcel($value);
    }

    public function testWithinThread(): void
    {
        $value = 1;
        $parcel = new ThreadedParcel($value);

        $thread = Thread::run(function (Channel $channel, ThreadedParcel $parcel) {
            $parcel->synchronized(function (int $value) {
                return $value + 1;
            });

            return 0;
        }, $parcel);

        $this->assertSame(0, $thread->join());
        $this->assertSame($value + 1, $parcel->unwrap());
    }
}
