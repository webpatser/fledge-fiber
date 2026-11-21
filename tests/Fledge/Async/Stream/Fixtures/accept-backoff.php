<?php declare(strict_types=1);

/*
 * Subprocess for ResourceServerSocketBackoffTest: lowers its own open file limit, fills every free descriptor so
 * accept() fails with EMFILE while connections are pending, and prints a JSON report.
 *
 * Usage: php accept-backoff.php <autoload> <resume|close>
 *   resume: measure CPU time for one second at the limit, then free descriptors and time the next accept.
 *   close:  close the listener while it backs off and let the loop run on.
 */

use Fledge\Async\Stream\BindContext;
use Fledge\Async\Stream\ResourceServerSocket;
use Fledge\Loop;
use Fledge\Loop\CallbackType;
use Psr\Log\AbstractLogger;

use function Fledge\Async\async;
use function Fledge\Async\delay;

require $argv[1];
$mode = $argv[2];

$report = static function (array $data): never {
    echo \json_encode($data), "\n";
    exit(0);
};

if (!\function_exists('posix_setrlimit')) {
    $report(['skip' => 'posix_setrlimit() is not available']);
}

$hard = \posix_getrlimit()['hard openfiles'];
if (!\posix_setrlimit(\POSIX_RLIMIT_NOFILE, 128, $hard === 'unlimited' ? \POSIX_RLIMIT_INFINITY : (int) $hard)) {
    $report(['skip' => 'cannot lower the open file limit']);
}

$logger = new class extends AbstractLogger {
    public array $warnings = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->warnings[] = (string) $message;
    }
};

$listener = \stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
$server = new ResourceServerSocket($listener, new BindContext(), logger: $logger);
$address = 'tcp://' . $server->getAddress()->toString();

// Warm-up: one full accept cycle through the readable callback loads every class the run needs and opens the poll
// driver's kernel context, since autoloading and the context need a free descriptor too.
$warmupAccept = async(static fn () => $server->accept());
delay(0.001);
$warmup = \stream_socket_client($address);
$warmupAccept->await()?->close();
\fclose($warmup);
delay(0.001);
$logger->warning('warm-up');
$logger->warnings = [];

// Three connections wait in the backlog; then every free descriptor is taken.
$clients = [];
for ($i = 0; $i < 3; $i++) {
    $clients[] = \stream_socket_client($address);
}

$fillers = [];
while ($filler = @\fopen('/dev/null', 'r')) {
    $fillers[] = $filler;
}

$cpu = static function (): float {
    $usage = \getrusage();

    return $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1e6
        + $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1e6;
};

$accepted = [];
$acceptor = async(static function () use ($server, &$accepted): void {
    while ($socket = $server->accept()) {
        $accepted[] = [\microtime(true), $socket];
    }
});

if ($mode === 'close') {
    delay(0.1); // The listener backs off by now.
    $warningsBeforeClose = \count($logger->warnings);
    $server->close();
    $acceptor->await();

    // With assertions on, a callback left on the closed listener raises an Error at the next tick.
    delay(0.05);

    $timers = \array_filter(
        Loop::getIdentifiers(),
        static fn (string $id) => \in_array(Loop::getType($id), [CallbackType::Delay, CallbackType::Repeat], true),
    );

    $report([
        'warnings_before_close' => $warningsBeforeClose,
        'accepted' => \count($accepted),
        'timers_left' => \count($timers),
    ]);
}

$start = $cpu();
delay(1.0);
$cpuAtLimit = $cpu() - $start;
$acceptedAtLimit = \count($accepted);
$warningsAtLimit = $logger->warnings;

// Free descriptors and open one more connection, since a kernel may drop backlogged connections on EMFILE (macOS).
\array_splice($fillers, 0, 8);
$freedAt = \microtime(true);
$clients[] = \stream_socket_client($address);

while (\count($accepted) === $acceptedAtLimit && \microtime(true) - $freedAt < 3.0) {
    delay(0.005);
}

$resumedAfter = isset($accepted[$acceptedAtLimit]) ? $accepted[$acceptedAtLimit][0] - $freedAt : null;

$server->close();
$acceptor->await();

$report([
    'cpu_at_limit' => $cpuAtLimit,
    'accepted_at_limit' => $acceptedAtLimit,
    'warnings_at_limit' => $warningsAtLimit,
    'resumed_after' => $resumedAfter,
    'warnings' => $logger->warnings,
]);
