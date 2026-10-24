<?php

declare(strict_types=1);

namespace Brewless\Laravel\Ops;

use RuntimeException;

/**
 * A release command that was refused or failed. Its message names the
 * command and nothing the command printed or threw, so it is the one reason
 * an ops answer gives: Brewless says which command stopped the release. The
 * error of the command itself is its previous exception, and only reported.
 */
final class ReleaseFailed extends RuntimeException {}
