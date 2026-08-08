<?php

declare(strict_types=1);

namespace Cbox\Platform\Plan;

readonly class PlanEntry
{
    /**
     * @param  list<FieldChange>  $changes  empty unless the previous body was retained
     */
    public function __construct(
        public PlanAction $action,
        public string $key,
        public ?string $hash,
        public array $changes = [],
    ) {}

    /** Whether this entry can say what changed, not merely that something did. */
    public function isDetailed(): bool
    {
        return $this->changes !== [];
    }
}
