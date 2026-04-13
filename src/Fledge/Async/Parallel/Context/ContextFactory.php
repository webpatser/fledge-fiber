<?php declare(strict_types=1);

namespace Fledge\Async\Parallel\Context;

use Fledge\Async\Cancellation;

interface ContextFactory
{
    /**
     * Creates a new execution context.
     *
     * @param string|non-empty-list<string> $script Path to PHP script or array with first element as path and following
     *     elements as options to the PHP script (e.g.: ['bin/worker', 'ArgumentValue', '--option', 'OptionValue'].
     */
    public function start(string|array $script, ?Cancellation $cancellation = null): Context;
}
