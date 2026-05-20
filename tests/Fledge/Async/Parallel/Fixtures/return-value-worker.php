<?php declare(strict_types=1);

use Fledge\Async\Sync\Channel;

return function (Channel $channel): string {
    return 'fledge-result';
};
