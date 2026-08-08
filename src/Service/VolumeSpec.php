<?php

declare(strict_types=1);

namespace Cbox\Platform\Service;

/**
 * One persistent disk, resolved for the compiler.
 */
readonly class VolumeSpec
{
    /**
     * @param  list<string>  $processes  empty means every process
     */
    public function __construct(
        public string $name,
        public string $mountPath,
        public string $size,
        public array $processes = [],
    ) {}

    public function mountedBy(string $process): bool
    {
        return $this->processes === [] || in_array($process, $this->processes, true);
    }
}
