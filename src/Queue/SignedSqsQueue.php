<?php

declare(strict_types=1);

namespace Brewless\Laravel\Queue;

use Illuminate\Queue\SqsQueue;

final class SignedSqsQueue extends SqsQueue
{
    use SignsJobs;
}
