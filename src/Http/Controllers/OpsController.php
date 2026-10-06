<?php

declare(strict_types=1);

namespace Brewless\Laravel\Http\Controllers;

use Brewless\Laravel\Ops\Runtime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * The routes an application's own containers answer on for Brewless. Every
 * answer is JSON with `ok`; a failure carries the reason, which is the
 * application's own error and never a credential.
 */
final class OpsController
{
    public function __construct(private readonly Runtime $runtime) {}

    public function release(): JsonResponse
    {
        return $this->attempt(fn (): array => $this->runtime->release());
    }

    public function schedule(): JsonResponse
    {
        return $this->attempt(fn (): array => $this->runtime->schedule());
    }

    public function queue(): JsonResponse
    {
        return $this->attempt(fn (): array => $this->runtime->queue());
    }

    public function retryFailed(): JsonResponse
    {
        return $this->attempt(fn (): array => $this->runtime->retryFailed());
    }

    public function status(): JsonResponse
    {
        return $this->attempt(fn (): array => $this->runtime->status());
    }

    public function command(Request $request): JsonResponse
    {
        $command = $request->json('command');

        // One command on one line.
        if (! is_string($command) || trim($command) === '' || strlen($command) > 2000 || preg_match('/[\r\n]/', $command) === 1) {
            return response()->json(['ok' => false, 'error' => 'Give one command on one line.'], 422);
        }

        return $this->attempt(fn (): array => $this->runtime->command($command));
    }

    /**
     * A trigger of the provider posts its JSON arguments to the root of the
     * container and cannot choose a path, so the task is named in the body.
     */
    public function trigger(Request $request): JsonResponse
    {
        return match ($request->json('task')) {
            'schedule' => $this->schedule(),
            'queue' => $this->queue(),
            // The usual tick: plan first, then work what was planned.
            'tick' => $this->attempt(fn (): array => ['schedule' => $this->runtime->schedule(), 'queue' => $this->runtime->queue()]),
            default => response()->json(['ok' => false, 'error' => 'unknown task'], 422),
        };
    }

    /**
     * @param  callable(): array<string, mixed>  $operation
     */
    private function attempt(callable $operation): JsonResponse
    {
        try {
            return response()->json(['ok' => true] + $operation());
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['ok' => false, 'error' => $exception->getMessage()], 500);
        }
    }
}
