<?php

declare(strict_types=1);

namespace Cbox\Platform\Plan;

use Cbox\Platform\Contracts\Planner;
use Cbox\Platform\Manifest\Manifest;
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

    /**
     * The same plan, but able to say WHAT changed — `~ spec.replicas 1 → 3`
     * rather than `~ Deployment/web`.
     *
     * THREE INPUTS, NOT TWO, and the split is the point. `$appliedHashes` is
     * authoritative and complete: it decides every action, exactly as
     * {@see self::plan()} would. `$appliedBodies` is best-effort and may hold
     * fewer objects — a consumer retains bodies per resource, and must not
     * retain them for Secrets at all — so an object with no retained body
     * simply gets no detail rather than a diff against an empty one, which
     * would report every field it has as added.
     *
     * SECRETS ARE ALWAYS REDACTED, whatever a caller passes. A plan is rendered
     * into a browser, an API response and a log, so a field diff that printed
     * secret material would publish it to all three. The path survives; the
     * value does not.
     *
     * @param  array<string, string>  $appliedHashes
     */
    public function planAgainst(ManifestSet $desired, array $appliedHashes, ManifestSet $appliedBodies): Plan
    {
        $plan = $this->plan($desired, $appliedHashes);
        $diff = new ManifestDiff;

        // Indexed once, not searched per entry. `find()` is a linear scan, and
        // scanning it inside a loop over the entries makes this quadratic —
        // measured at 800 objects, where it cost 12x what 200 did for 4x the
        // work. A consumer compiling a large set would pay that on every plan.
        $byKey = [];

        foreach ([$appliedBodies, $desired] as $index => $set) {
            foreach ($set->manifests as $manifest) {
                $byKey[$index][$manifest->key()] = $manifest;
            }
        }

        $entries = [];

        foreach ($plan->entries as $entry) {
            $before = $byKey[0][$entry->key] ?? null;
            $after = $byKey[1][$entry->key] ?? null;

            if ($entry->action !== PlanAction::Update
                || ! $before instanceof Manifest
                || ! $after instanceof Manifest) {
                $entries[] = $entry;

                continue;
            }

            $entries[] = new PlanEntry(
                action: $entry->action,
                key: $entry->key,
                hash: $entry->hash,
                changes: $diff->compare(
                    $before->body,
                    $after->body,
                    redactValues: self::holdsSecrets($after),
                ),
            );
        }

        return new Plan($entries);
    }

    /**
     * Does this object hold material that must never reach a plan?
     *
     * By KIND, not by inspecting values: a Secret is a Secret whether its
     * payload arrived as `data` or `stringData`, and guessing from content is
     * how the one shape nobody thought of gets printed.
     */
    private static function holdsSecrets(Manifest $manifest): bool
    {
        return $manifest->kind === 'Secret';
    }
}
