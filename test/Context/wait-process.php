<?php

use Amp\Parallel\Sync\Channel;

return function (Channel $channel) use ($argv): string {
    \usleep(1000000);
    return 'test';
};
