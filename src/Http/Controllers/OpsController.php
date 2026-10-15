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

    /**
     * The release commands. Brewless sends the ones of the project's
     * brewless.yml as `commands`; without them the application's own run.
     */
    public function release(Request $request): JsonResponse
    {
        $commands = $request->json('commands');

        if ($commands === null) {
            return $this->attempt(fn (): array => $this->runtime->release());
        }

        if (! $this->lines($commands, 20)) {
            return response()->json(['ok' => false, 'error' => 'Give the release commands as a list, one command per line.'], 422);
        }

        /** @var list<string> $commands */
        return $this->attempt(fn (): array => $this->runtime->releaseCommands($commands));
    }

    public function schedule(): JsonResponse
    {
        return $this->attempt(fn (): array => $this->runtime->schedule());
    }

    public function queue(Request $request): JsonResponse
    {
        $queues = $this->queues($request);

        if ($queues === false) {
            return response()->json(['ok' => false, 'error' => 'Name the queues as a list of names.'], 422);
        }

        return $this->attempt(fn (): array => $this->runtime->queue($queues));
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
        $queues = $this->queues($request);

        if ($queues === false) {
            return response()->json(['ok' => false, 'error' => 'Name the queues as a list of names.'], 422);
        }

        return match ($request->json('task')) {
            'schedule' => $this->schedule(),
            'queue' => $this->attempt(fn (): array => $this->runtime->queue($queues)),
            // The usual tick: plan first, then work what was planned.
            'tick' => $this->attempt(fn (): array => ['schedule' => $this->runtime->schedule(), 'queue' => $this->runtime->queue($queues)]),
            default => response()->json(['ok' => false, 'error' => 'unknown task'], 422),
        };
    }

    /**
     * The queues to work, as the trigger names them (`queues`), the first
     * first; null when it names none and the application's default is meant.
     * False when what it sent is not a list of names.
     *
     * @return list<string>|null|false
     */
    private function queues(Request $request): array|null|false
    {
        $queues = $request->json('queues');

        if ($queues === null) {
            return null;
        }

        if (! is_array($queues) || ! array_is_list($queues) || $queues === [] || count($queues) > 20) {
            return false;
        }

        foreach ($queues as $queue) {
            if (! is_string($queue) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,79}$/', $queue) !== 1) {
                return false;
            }
        }

        /** @var list<string> $queues */
        return $queues;
    }

    /**
     * A list of at most this many commands, each one line.
     */
    private function lines(mixed $commands, int $most): bool
    {
        if (! is_array($commands) || ! array_is_list($commands) || $commands === [] || count($commands) > $most) {
            return false;
        }

        foreach ($commands as $command) {
            if (! is_string($command) || trim($command) === '' || strlen($command) > 2000 || preg_match('/[\r\n]/', $command) === 1) {
                return false;
            }
        }

        return true;
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
