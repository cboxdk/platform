<?php

declare(strict_types=1);

namespace Cbox\Platform\Service;

/**
 * One non-serving process of an application.
 *
 * @param  list<string>  $command  argv, never a shell string
 */
readonly class ProcessSpec
{
    /**
     * @param  list<string>  $command
     */
    public function __construct(
        public string $name,
        public array $command,
        public int $replicas,
    ) {}
}
