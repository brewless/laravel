<?php

declare(strict_types=1);

namespace Brewless\Laravel\Queue;

use Illuminate\Queue\DatabaseQueue;

final class SignedDatabaseQueue extends DatabaseQueue
{
    use SignsJobs;
}
