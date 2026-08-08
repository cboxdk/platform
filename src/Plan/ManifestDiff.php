<?php

declare(strict_types=1);

namespace Cbox\Platform\Plan;

/**
 * What changed inside one object, field by field.
 *
 * Separate from {@see HashPlanner} on purpose. A hash comparison needs only the
 * digests a consumer already stores; this needs the previous BODY, which is
 * larger and which not every consumer wants to keep — a vendored addon set runs
 * to megabytes and nobody reads a field diff of it. So the planner keeps
 * working on hashes alone, and a consumer that retains bodies gets this on top.
 *
 * Pure, ordered, and deterministic like everything else here: the same pair of
 * bodies always produces the same list in the same order.
 */
class ManifestDiff
{
    /** What a redacted value renders as. */
    public const REDACTED = '•••';

    /**
     * RAW BY DEFAULT, because two arrays cannot know what they hold.
     *
     * Anything that knows it is diffing secret material must say so — see
     * {@see HashPlanner::planAgainst()}, which redacts every Secret without
     * being asked. A plan is rendered into a browser, an API response and a
     * log; a diff that prints `stringData.APP_KEY old → new` has published the
     * customer's key to all three.
     *
     * Redaction keeps the PATH, which is not secret and is the useful part: the
     * key names are already visible in the product, and "this key changed" is
     * exactly what a plan should say.
     *
     * @param  array<array-key, mixed>  $before
     * @param  array<array-key, mixed>  $after
     * @return list<FieldChange>
     */
    public function compare(array $before, array $after, bool $redactValues = false): array
    {
        $changes = [];

        $this->walk($before, $after, '', $changes);

        if ($redactValues) {
            $changes = array_map(static fn (FieldChange $change): FieldChange => new FieldChange(
                path: $change->path,
                kind: $change->kind,
                before: $change->before === null ? null : self::REDACTED,
                after: $change->after === null ? null : self::REDACTED,
            ), $changes);
        }

        // Sorted by path so two runs over the same objects read identically,
        // and so a plan does not reorder itself between refreshes.
        usort($changes, static fn (FieldChange $a, FieldChange $b): int => strcmp($a->path, $b->path));

        return $changes;
    }

    /**
     * @param  array<array-key, mixed>  $before
     * @param  array<array-key, mixed>  $after
     * @param  list<FieldChange>  $changes
     */
    private function walk(array $before, array $after, string $prefix, array &$changes): void
    {
        /** @var list<array-key> $keys */
        $keys = array_keys($before + $after);
        sort($keys);

        foreach ($keys as $key) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            $inBefore = array_key_exists($key, $before);
            $inAfter = array_key_exists($key, $after);

            if (! $inAfter) {
                $changes[] = new FieldChange(
                    $path,
                    FieldChangeKind::Removed,
                    FieldChange::render($before[$key]),
                    null,
                );

                continue;
            }

            if (! $inBefore) {
                $changes[] = new FieldChange(
                    $path,
                    FieldChangeKind::Added,
                    null,
                    FieldChange::render($after[$key]),
                );

                continue;
            }

            $was = $before[$key];
            $is = $after[$key];

            // Recurse into maps so `spec.replicas` is reported rather than
            // `spec`. NOT into lists: a container list that gained an element
            // reports every subsequent index as changed, which is noise
            // describing a shift rather than an edit.
            if (is_array($was) && is_array($is) && ! array_is_list($was) && ! array_is_list($is)) {
                $this->walk($was, $is, $path, $changes);

                continue;
            }

            if ($was === $is) {
                continue;
            }

            $changes[] = new FieldChange(
                $path,
                FieldChangeKind::Changed,
                FieldChange::render($was),
                FieldChange::render($is),
            );
        }
    }
}
