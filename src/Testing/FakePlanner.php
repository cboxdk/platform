<?php

declare(strict_types=1);

namespace Cbox\Platform\Testing;

use Cbox\Platform\Contracts\Planner;
use Cbox\Platform\Manifest\ManifestSet;
use Cbox\Platform\Plan\HashPlanner;
use Cbox\Platform\Plan\Plan;

/**
 * A planner that answers whatever the test needs, for exercising the branch a
 * consumer takes on an empty plan.
 *
 * "A deploy that changes nothing is a no-op" is a property worth keeping, and
 * the code paths guarding it — skip the apply, skip the release, skip the
 * event — are the ones a real planner makes tedious to reach.
 */
class FakePlanner implements Planner
{
    /** @var list<array{desired: ManifestSet, applied: array<string, string>}> */
    public array $planned = [];

    public function __construct(private ?Plan $returns = null) {}

    /** Every plan reports no changes. */
    public static function unchanged(): self
    {
        return new self(new Plan([]));
    }

    public function returning(Plan $plan): self
    {
        $this->returns = $plan;

        return $this;
    }

    public function plan(ManifestSet $desired, array $appliedHashes): Plan
    {
        $this->planned[] = ['desired' => $desired, 'applied' => $appliedHashes];

        // Falling through to the real arithmetic keeps the fake honest by
        // default: it only lies where a test asked it to.
        return $this->returns ?? new HashPlanner()->plan($desired, $appliedHashes);
    }
}
