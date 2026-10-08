<?php

use Fledge\Fiber\Database\Native\NativeMariaDbConnector;
use Fledge\Fiber\Database\Native\NativeMySqlConnector;
use Fledge\Fiber\Database\Native\NativePdoPool;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;

/**
 * Guard rails of the native connectors: persistent rejection, fiberio missing
 * or optional, ZTS message and lazy connect. No server is needed.
 */

/**
 * Run PHP code in a fresh subprocess on the default ini, i.e. without the
 * fiberio.so that the test run loads through `-d extension=`. The code prints
 * one JSON document; the decoded result is returned.
 *
 * @return array<string, mixed>
 */
function nativeGuardSubprocess(string $code): array
{
    $autoload = dirname(__DIR__, 4).'/vendor/autoload.php';

    $process = proc_open(
        [PHP_BINARY, '-d', 'display_errors=stderr', '-r', 'require '.var_export($autoload, true).";\n".$code],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    $decoded = json_decode($stdout, true);

    if (! is_array($decoded)) {
        test()->fail("subprocess did not print JSON. stdout: {$stdout} stderr: {$stderr}");
    }

    return $decoded;
}

/**
 * Reset the once-per-process warning flag of the connectors.
 */
function nativeGuardResetWarning(): void
{
    (new ReflectionProperty(NativeMySqlConnector::class, 'warnedFiberIoMissing'))->setValue(null, false);
}

uses()->beforeEach(function () {
    $this->previousFacadeApp = Facade::getFacadeApplication();
    $this->logged = new ArrayObject;

    $container = new Container;
    $container->instance('log', new class($this->logged)
    {
        public function __construct(private ArrayObject $logged) {}

        public function warning(string $message): void
        {
            $this->logged[] = $message;
        }
    });

    Facade::clearResolvedInstance('log');
    Facade::setFacadeApplication($container);

    nativeGuardResetWarning();
})->afterEach(function () {
    nativeDriverReset();
    nativeGuardResetWarning();

    Facade::clearResolvedInstance('log');
    Facade::setFacadeApplication($this->previousFacadeApp);
});

dataset('native connectors', [
    'mysql' => [NativeMySqlConnector::class],
    'mariadb' => [NativeMariaDbConnector::class],
]);

it('rejects PDO::ATTR_PERSISTENT with an InvalidArgumentException', function (string $connector) {
    (new $connector)->connect([
        'host' => '127.0.0.1',
        'database' => 'unused',
        'options' => [PDO::ATTR_PERSISTENT => true],
    ]);
})->with('native connectors')->throws(InvalidArgumentException::class, 'PDO::ATTR_PERSISTENT');

it('rejects PDO::ATTR_PERSISTENT even when fiberio is optional', function (string $connector) {
    (new $connector)->connect([
        'host' => '127.0.0.1',
        'database' => 'unused',
        'fiberio' => 'optional',
        'options' => [PDO::ATTR_PERSISTENT => true],
    ]);
})->with('native connectors')->throws(InvalidArgumentException::class);

it('throws a RuntimeException naming the pie package when fiberio is missing', function () {
    $result = nativeGuardSubprocess(<<<'PHP'
        $out = ['fiberio' => extension_loaded('fiberio'), 'errors' => []];

        foreach ([Fledge\Fiber\Database\Native\NativeMySqlConnector::class, Fledge\Fiber\Database\Native\NativeMariaDbConnector::class] as $class) {
            try {
                (new $class)->connect(['host' => '127.0.0.1', 'database' => 'unused']);
                $out['errors'][$class] = null;
            } catch (Throwable $e) {
                $out['errors'][$class] = [get_class($e), $e->getMessage()];
            }
        }

        echo json_encode($out);
        PHP);

    if ($result['fiberio']) {
        $this->markTestSkipped('fiberio is part of the default php.ini, cannot run a subprocess without it');
    }

    expect($result['errors'])->toHaveCount(2);

    foreach ($result['errors'] as $class => $error) {
        expect($error)->not->toBeNull("{$class} did not throw")
            ->and($error[0])->toBe(RuntimeException::class)
            ->and($error[1])->toContain('pie install webpatser/php-fiberio');
    }
});

it('continues and logs one warning when fiberio is optional and missing', function () {
    $result = nativeGuardSubprocess(<<<'PHP'
        $logged = new ArrayObject;
        $container = new Illuminate\Container\Container;
        $container->instance('log', new class($logged) {
            public function __construct(private ArrayObject $logged) {}
            public function warning(string $message): void { $this->logged[] = $message; }
        });
        Illuminate\Support\Facades\Facade::setFacadeApplication($container);

        $out = ['fiberio' => extension_loaded('fiberio'), 'pools' => [], 'errors' => []];
        $config = ['host' => '127.0.0.1', 'database' => 'unused', 'fiberio' => 'optional'];

        // Two connectors, two connects each: still one warning for the process.
        foreach ([Fledge\Fiber\Database\Native\NativeMySqlConnector::class, Fledge\Fiber\Database\Native\NativeMariaDbConnector::class] as $class) {
            foreach ([1, 2] as $_) {
                try {
                    $out['pools'][] = get_class((new $class)->connect($config));
                } catch (Throwable $e) {
                    $out['errors'][] = get_class($e).': '.$e->getMessage();
                }
            }
        }

        $out['logged'] = $logged->getArrayCopy();

        echo json_encode($out);
        PHP);

    if ($result['fiberio']) {
        $this->markTestSkipped('fiberio is part of the default php.ini, cannot run a subprocess without it');
    }

    expect($result['errors'])->toBe([])
        ->and($result['pools'])->toBe(array_fill(0, 4, NativePdoPool::class))
        ->and($result['logged'])->toHaveCount(1)
        ->and($result['logged'][0])->toContain('fiberio extension not loaded')
        ->and($result['logged'][0])->toContain('pie install webpatser/php-fiberio');
});

it('explains that fiberio supports NTS builds only when PHP is thread safe', function (string $connector) {
    if (! PHP_ZTS) {
        $this->markTestSkipped('Not a ZTS build: the ZTS guard cannot be reached on this PHP binary');
    }

    if (! extension_loaded('fiberio')) {
        $this->markTestSkipped('fiberio extension not loaded');
    }

    try {
        (new $connector)->connect(['host' => '127.0.0.1', 'database' => 'unused']);
        $this->fail('Expected a RuntimeException on a ZTS build');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('non-thread-safe (NTS)');
    }
})->with('native connectors');

it('does not open a connection in connect()', function (string $connector) {
    // TEST-NET-1 address, nothing listens there: an eager connect would hang or throw.
    $pool = (new $connector)->connect([
        'host' => '192.0.2.1',
        'port' => 1,
        'database' => 'unused',
        'username' => 'nobody',
        'password' => 'nothing',
        'fiberio' => 'optional',
        'pool_size' => 2,
    ]);

    expect($pool)->toBeInstanceOf(NativePdoPool::class)
        ->and($pool->count())->toBe(0)
        ->and($pool->getIdleCount())->toBe(0)
        ->and($pool->getLeasedCount())->toBe(0)
        ->and($pool->getMaxSize())->toBe(2);
})->with('native connectors');
