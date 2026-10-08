<?php

use Fledge\Fiber\Database\Native\NativeMySqlConnector;

/**
 * fiberio hooks config parsing. Runs in a subprocess on the default ini so the
 * FiberIo\HOOK_* constants can be defined (or left undefined, the php-fiberio
 * v0.1.0 case) without leaking into the rest of the suite.
 *
 * @return array<string, mixed>
 */
function nativeHooksSubprocess(string $code, ?string $env = null, bool $defineConstants = true): array
{
    $autoload = dirname(__DIR__, 4).'/vendor/autoload.php';
    $connector = dirname(__DIR__, 4).'/src/Fledge/Fiber/Database/Native/NativeMySqlConnector.php';
    $define = 'require_once '.var_export($connector, true).";\n";
    $define .= $defineConstants
        ? "define('FiberIo\\\\HOOK_SLEEP', 1); define('FiberIo\\\\HOOK_DNS', 2); define('FiberIo\\\\HOOK_SSL', 4); define('FiberIo\\\\HOOK_ALL', 7);\n"
        : '';

    $process = proc_open(
        [PHP_BINARY, '-n', '-d', 'display_errors=stderr', '-r', 'require '.var_export($autoload, true).";\n".$define.$code],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        $env === null ? null : ['FLEDGE_FIBERIO_HOOKS' => $env],
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

$class = NativeMySqlConnector::class;

/**
 * PHP that binds a container with `fledge-http.fiberio_hooks` set to $value
 * (no config binding at all for the sentinel 'unbound') and a log that
 * records warnings into $GLOBALS['warnings'].
 */
function nativeHooksContainer(mixed $value = 'unbound'): string
{
    $code = '$app = new Illuminate\\Container\\Container; Illuminate\\Container\\Container::setInstance($app);'."\n";
    $code .= '$GLOBALS[\'warnings\'] = [];'."\n";
    $code .= '$app->instance(\'log\', new class { public function warning($m) { $GLOBALS[\'warnings\'][] = $m; } });'."\n";

    if ($value !== 'unbound') {
        $items = var_export(['fledge-http' => ['fiberio_hooks' => $value]], true);
        $code .= '$app->instance(\'config\', new class('.$items.') { public function __construct(private array $i) {} public function get($k, $d = null) { return data_get($this->i, $k, $d); } });'."\n";
    }

    return $code;
}

it('parses the fiberio_hooks config value', function (mixed $value, int $expected) use ($class) {
    $result = nativeHooksSubprocess(
        nativeHooksContainer().'echo json_encode([\'mask\' => '.$class.'::resolveHooks([\'fiberio_hooks\' => '.var_export($value, true).'])]);'
    );

    expect($result['mask'])->toBe($expected);
})->with([
    'single' => ['sleep', 1],
    'list' => ['sleep,dns,ssl', 7],
    'spaces and case' => [' Sleep , SSL ', 5],
    'all' => ['all', 7],
    'none' => ['none', 0],
    'zero string' => ['0', 0],
    'false string' => ['false', 0],
    'off' => ['off', 0],
    'no' => ['no', 0],
    'empty string' => ['', 0],
    'bool false' => [false, 0],
    'bool true' => [true, 7],
    'unknown ignored' => ['dns,bogus', 2],
    'only unknown resolves to none' => ['bogus', 0],
    'array' => [['dns', 'ssl'], 6],
]);

it('logs one warning for unknown hook names', function () use ($class) {
    $result = nativeHooksSubprocess(
        nativeHooksContainer().$class.'::resolveHooks([\'fiberio_hooks\' => \'dns,bogus\']); '
        .$class.'::resolveHooks([\'fiberio_hooks\' => \'other\']); echo json_encode([\'warnings\' => $GLOBALS[\'warnings\']]);'
    );

    expect($result['warnings'])->toHaveCount(1)
        ->and($result['warnings'][0])->toContain('bogus');
});

it('ignores unknown names without a logger', function () use ($class) {
    $result = nativeHooksSubprocess('echo json_encode([\'mask\' => '.$class.'::resolveHooks([\'fiberio_hooks\' => \'bogus\'])]);');

    expect($result['mask'])->toBe(0);
});

it('falls back to the fledge-http.fiberio_hooks app config, then all', function (mixed $app, int $expected) use ($class) {
    $result = nativeHooksSubprocess(
        nativeHooksContainer($app).'echo json_encode([\'mask\' => '.$class.'::resolveHooks([])]);',
    );

    expect($result['mask'])->toBe($expected);
})->with([
    'no container config' => ['unbound', 7],
    'app config unset' => [null, 7],
    'app config list' => ['sleep,dns', 3],
    'app config none' => ['none', 0],
]);

it('works without any container', function () use ($class) {
    $result = nativeHooksSubprocess('echo json_encode([\'mask\' => '.$class.'::resolveHooks([])]);');

    expect($result['mask'])->toBe(7);
});

it('prefers the connection key over the app config and ignores the raw env var', function () use ($class) {
    $connection = nativeHooksSubprocess(
        nativeHooksContainer('sleep').'echo json_encode([\'mask\' => '.$class.'::resolveHooks([\'fiberio_hooks\' => \'ssl\'])]);',
    );
    $env = nativeHooksSubprocess(
        nativeHooksContainer().'echo json_encode([\'mask\' => '.$class.'::resolveHooks([])]);',
        'sleep',
    );

    expect($connection['mask'])->toBe(4)
        ->and($env['mask'])->toBe(7);
});

it('ships fiberio_hooks in the fledge-http config', function () {
    $config = require dirname(__DIR__, 4).'/src/Fledge/Fiber/config/fledge-http.php';

    expect($config)->toHaveKey('fiberio_hooks');
});

it('detects php-fiberio v0.1.0 (no hooks constants) for the enable fallback', function () use ($class) {
    $old = nativeHooksSubprocess('echo json_encode([\'hooks\' => '.$class.'::fiberIoSupportsHooks()]);', null, false);
    $new = nativeHooksSubprocess('echo json_encode([\'hooks\' => '.$class.'::fiberIoSupportsHooks()]);');

    expect($old['hooks'])->toBeFalse()
        ->and($new['hooks'])->toBeTrue();
});
