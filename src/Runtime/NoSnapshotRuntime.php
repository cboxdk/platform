<?php

declare(strict_types=1);

namespace Cbox\Platform\Runtime;

use Cbox\Platform\Contracts\SnapshotRuntime;

/**
 * The default: this cell cannot snapshot. Scale-to-zero still works — it just
 * falls back to a cold start, which is the honest behaviour on BYO nodes we do
 * not control. Nothing is added to the pod.
 */
class NoSnapshotRuntime implements SnapshotRuntime
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function runtimeClassName(): ?string
    {
        return null;
    }

    public function annotations(string $containerName, int $port, int $idleTimeoutSeconds): array
    {
        return [];
    }

    public function environment(): array
    {
        return [];
    }
}
