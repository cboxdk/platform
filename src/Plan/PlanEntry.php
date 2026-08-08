<?php

declare(strict_types=1);

namespace Cbox\Platform\Plan;

readonly class PlanEntry
{
    public function __construct(
        public PlanAction $action,
        public string $key,
        public ?string $hash,
    ) {}
}
