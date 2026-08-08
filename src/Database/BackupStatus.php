<?php

declare(strict_types=1);

namespace Cbox\Platform\Database;

/**
 * A backup's lifecycle, the same one-way shape a Build has: pending → running →
 * (completed | failed). A terminal state never changes — a completed backup that
 * later turns out unreadable is a new fact, not a rewritten one.
 */
enum BackupStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Failed;
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Pending => $next === self::Running || $next === self::Failed,
            self::Running => $next === self::Completed || $next === self::Failed,
            self::Completed, self::Failed => false,
        };
    }
}
