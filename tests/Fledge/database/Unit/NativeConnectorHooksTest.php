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

it('parses the fiberio_hooks config value', function (mixed $value, int $expected) use ($class) {
    $result = nativeHooksSubprocess(
        'echo json_encode([\'mask\' => '.$class.'::resolveHooks([\'fiberio_hooks\' => '.var_export($value, true).'])]);'
    );

    expect($result['mask'])->toBe($expected);
})->with([
    'single' => ['sleep', 1],
    'list' => ['sleep,dns,ssl', 7],
    'spaces and case' => [' Sleep , SSL ', 5],
    'all' => ['all', 7],
    'none' => ['none', 0],
    'unknown ignored' => ['dns,bogus', 2],
    'only unknown falls back to all' => ['bogus', 7],
    'array' => [['dns', 'ssl'], 6],
]);

it('defaults to all hooks and reads FLEDGE_FIBERIO_HOOKS', function (?string $env, int $expected) use ($class) {
    $result = nativeHooksSubprocess(
        'echo json_encode([\'mask\' => '.$class.'::resolveHooks([])]);',
        $env ?? '',
    );

    expect($result['mask'])->toBe($expected);
})->with([
    'unset' => [null, 7],
    'env list' => ['sleep,dns', 3],
    'env none' => ['none', 0],
]);

it('prefers the config key over the env var', function () use ($class) {
    $result = nativeHooksSubprocess(
        'echo json_encode([\'mask\' => '.$class.'::resolveHooks([\'fiberio_hooks\' => \'ssl\'])]);',
        'sleep',
    );

    expect($result['mask'])->toBe(4);
});

it('detects php-fiberio v0.1.0 (no hooks constants) for the enable fallback', function () use ($class) {
    $old = nativeHooksSubprocess('echo json_encode([\'hooks\' => '.$class.'::fiberIoSupportsHooks()]);', null, false);
    $new = nativeHooksSubprocess('echo json_encode([\'hooks\' => '.$class.'::fiberIoSupportsHooks()]);');

    expect($old['hooks'])->toBeFalse()
        ->and($new['hooks'])->toBeTrue();
});
