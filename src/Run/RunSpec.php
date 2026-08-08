<?php

declare(strict_types=1);

namespace Cbox\Platform\Run;

use Cbox\Platform\Service\ServiceSpec;

readonly class RunSpec
{
    /**
     * @param  list<string>  $command  argv, never a shell string
     */
    public function __construct(
        public string $runId,
        public string $jobName,
        public array $command,
        public ServiceSpec $service,
    ) {}
}
