<?php

declare(strict_types=1);

namespace Cbox\Platform\Plan;

use Cbox\Platform\Contracts\Planner;
use Cbox\Platform\Manifest\ManifestSet;

/**
 * Plan = pure hash comparison. Because the compiler is deterministic and
 * hashes are canonical, "did anything change" never needs to touch a cluster.
 */
class HashPlanner implements Planner
{
    public function plan(ManifestSet $desired, array $appliedHashes): Plan
    {
        $entries = [];
        $desiredHashes = $desired->hashes();

        foreach ($desiredHashes as $key => $hash) {
            $entries[] = new PlanEntry(
                action: match (true) {
                    ! array_key_exists($key, $appliedHashes) => PlanAction::Create,
                    $appliedHashes[$key] !== $hash => PlanAction::Update,
                    default => PlanAction::Unchanged,
                },
                key: $key,
                hash: $hash,
            );
        }

        // Applied before, absent now — the plan must say so explicitly, or
        // removals silently leak resources in the customer's cluster.
        foreach ($appliedHashes as $key => $hash) {
            if (! array_key_exists($key, $desiredHashes)) {
                $entries[] = new PlanEntry(action: PlanAction::Delete, key: $key, hash: null);
            }
        }

        return new Plan($entries);
    }
}
