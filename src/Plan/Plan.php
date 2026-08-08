<?php

declare(strict_types=1);

namespace Cbox\Platform\Plan;

/**
 * The diff between desired manifests and what was last applied — the
 * product's signature `plan` output. Pure data; rendering is the UI's job.
 */
readonly class Plan
{
    /**
     * @param  list<PlanEntry>  $entries
     */
    public function __construct(
        public array $entries,
    ) {}

    public function hasChanges(): bool
    {
        foreach ($this->entries as $entry) {
            if ($entry->action !== PlanAction::Unchanged) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, int>
     */
    public function summary(): array
    {
        $counts = ['create' => 0, 'update' => 0, 'delete' => 0, 'unchanged' => 0];

        foreach ($this->entries as $entry) {
            $counts[$entry->action->value]++;
        }

        return $counts;
    }
}
