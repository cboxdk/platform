<?php

declare(strict_types=1);

namespace Cbox\Platform\Service;

/**
 * A service's desired lifecycle — the customer's explicit scale-to-zero intent,
 * stored as authoritative intent. Only two states are a *desire*: keep it
 * running, or hold it suspended (pinned to zero regardless of traffic).
 * `resuming` is never a desired state — it is an observed, transient phase the
 * mechanism surfaces while waking a suspended workload, and lives in
 * {@see WorkloadPhase}.
 */
enum LifecycleState: string
{
    case Running = 'running';
    case Suspended = 'suspended';

    public static function fromString(string $value): self
    {
        return self::tryFrom($value) ?? self::Running;
    }

    public function isSuspended(): bool
    {
        return $this === self::Suspended;
    }
}
