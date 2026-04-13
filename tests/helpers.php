<?php

if (! function_exists('test_env')) {
    /**
     * Get an environment variable with a default fallback.
     */
    function test_env(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);

        return $value !== false ? $value : $default;
    }
}
