<?php

namespace Fledge\Fiber\Livewire\Mechanisms;

use Fledge\Async\Sync\LocalSemaphore;
use Livewire\Features\SupportScriptsAndAssets\SupportScriptsAndAssets;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Livewire\Mechanisms\HandleRequests\StreamedResponse;

use function Fledge\Async\async;
use function Fledge\Async\Future\awaitAll;
use function Livewire\trigger;

/**
 * Fiber-powered HandleRequests that processes batched Livewire component
 * updates concurrently instead of sequentially.
 *
 * When a Livewire request contains multiple component payloads (e.g., a
 * dashboard with 5 components), each update runs in its own Fiber. With
 * non-blocking I/O drivers (Fledge Async database, Redis, HTTP), the updates
 * interleave during I/O waits — wall-clock time approaches the slowest
 * component rather than the sum of all components.
 */
class FiberHandleRequests extends HandleRequests
{
    /**
     * Handle an update request with concurrent Fiber execution.
     */
    public function handleUpdate()
    {
        if (! $this->shouldUseFibers()) {
            return parent::handleUpdate();
        }

        // --- Validation (same as parent) ---

        if (request()->route()?->getName() === 'default-livewire.update'
            && $this->findUpdateRoute()?->getName() !== 'default-livewire.update') {
            abort(404);
        }

        $maxSize = config('livewire.payload.max_size');

        if ($maxSize !== null) {
            $contentLength = request()->header('Content-Length', 0);

            if ($contentLength > $maxSize) {
                throw new \Livewire\Exceptions\PayloadTooLargeException($contentLength, $maxSize);
            }
        }

        $requestPayload = request('components');

        if (! is_array($requestPayload) || empty($requestPayload)) {
            abort(404);
        }

        foreach ($requestPayload as $component) {
            if (! is_array($component)
                || ! is_string($component['snapshot'] ?? null)
                || ! is_array($component['updates'] ?? null)
                || ! is_array($component['calls'] ?? null)
            ) {
                abort(404);
            }
        }

        $maxComponents = config('livewire.payload.max_components');

        if ($maxComponents !== null && count($requestPayload) > $maxComponents) {
            throw new \Livewire\Exceptions\TooManyComponentsException(count($requestPayload), $maxComponents);
        }

        // --- Pre-processing hooks ---

        $finish = trigger('request', $requestPayload);
        $requestPayload = $finish($requestPayload);

        // --- Single component: skip Fiber overhead ---

        if (count($requestPayload) <= 1) {
            return $this->processSequentially($requestPayload, $finish);
        }

        // --- Multiple components: process concurrently ---

        return $this->processConcurrently($requestPayload, $finish);
    }

    /**
     * Process components sequentially (single component or fallback).
     */
    protected function processSequentially(array $requestPayload, callable $finish): mixed
    {
        $componentResponses = [];

        foreach ($requestPayload as $componentPayload) {
            $componentResponses[] = $this->processComponent($componentPayload);
        }

        return $this->buildResponse($componentResponses, $finish);
    }

    /**
     * Process components concurrently using Fibers.
     */
    protected function processConcurrently(array $requestPayload, callable $finish): mixed
    {
        $maxConcurrent = config('fledge-livewire.max_concurrent', 10);
        $semaphore = new LocalSemaphore($maxConcurrent);

        $futures = [];

        foreach ($requestPayload as $key => $componentPayload) {
            $futures[$key] = async(function () use ($semaphore, $componentPayload) {
                $lock = $semaphore->acquire();

                try {
                    return $this->processComponent($componentPayload);
                } finally {
                    $lock->release();
                }
            });
        }

        [$errors, $results] = awaitAll($futures);
        ksort($results);

        // Build component responses, inserting error responses for failed components
        $componentResponses = [];

        foreach ($requestPayload as $key => $componentPayload) {
            if (isset($errors[$key])) {
                $e = $errors[$key];

                if ($e instanceof \TypeError && ! config('app.debug')) {
                    abort(419);
                }

                throw $e;
            }

            $componentResponses[] = $results[$key];
        }

        return $this->buildResponse($componentResponses, $finish);
    }

    /**
     * Process a single component payload.
     */
    protected function processComponent(array $componentPayload): array
    {
        $snapshot = json_decode($componentPayload['snapshot'], associative: true);
        $updates = $componentPayload['updates'];
        $calls = $componentPayload['calls'];

        try {
            [$snapshot, $effects] = app('livewire')->update($snapshot, $updates, $calls);
        } catch (\TypeError $e) {
            if (config('app.debug')) {
                throw $e;
            }

            abort(419);
        }

        return [
            'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'effects' => $effects,
        ];
    }

    /**
     * Build the final response payload.
     */
    protected function buildResponse(array $componentResponses, callable $finish): mixed
    {
        $responsePayload = [
            'components' => $componentResponses,
            'assets' => SupportScriptsAndAssets::getAssets(),
        ];

        $finish = trigger('response', $responsePayload);
        $payload = $finish($responsePayload);

        if (headers_sent()) {
            return new StreamedResponse(
                json_encode($payload),
                200,
                ['Content-Type' => 'application/json']
            );
        }

        return $payload;
    }

    /**
     * Determine if Fiber-based concurrency should be used.
     */
    protected function shouldUseFibers(): bool
    {
        if (! config('fledge-livewire.concurrent', true)) {
            return false;
        }

        if (! class_exists(\Revolt\EventLoop::class)) {
            return false;
        }

        return true;
    }
}
