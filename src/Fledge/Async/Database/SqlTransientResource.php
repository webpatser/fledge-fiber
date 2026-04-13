<?php declare(strict_types=1);

namespace Fledge\Async\Database;

use Fledge\Async\Closable;

interface SqlTransientResource extends Closable
{
    /**
     * Get the timestamp of the last usage of this resource.
     *
     * @return int Unix timestamp in seconds.
     */
    public function getLastUsedAt(): int;
}
