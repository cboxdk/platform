<?php

declare(strict_types=1);

namespace Cbox\Platform\Plan;

/**
 * One field that differs between what was applied and what is desired.
 *
 * The unit behind `~ replicas 1 → 3`. A hash says an object changed; this says
 * what about it changed, which is the difference between a plan a person can
 * approve and a plan they can only accept.
 *
 * Values are rendered rather than raw so a consumer can print one without
 * knowing whether it was an integer, a list or a nested map. Rendering the
 * whole subtree of a deeply nested change would drown the useful line, so a
 * container is summarised.
 */
readonly class FieldChange
{
    public function __construct(
        /** Dotted path into the object body, e.g. `spec.replicas`. */
        public string $path,
        public FieldChangeKind $kind,
        public ?string $before,
        public ?string $after,
    ) {}

    /**
     * A value as a plan would print it.
     */
    public static function render(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            // A count, not the contents. `spec.template.spec.containers` is a
            // list of maps of lists; printing it turns one changed line into
            // forty and hides the one that mattered.
            return array_is_list($value)
                ? '['.count($value).' items]'
                : '{'.count($value).' keys}';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        // An object or a resource in a manifest body would be a bug upstream,
        // but a plan is diagnostic output: it should name what it found rather
        // than fail while someone is trying to see what changed.
        return get_debug_type($value);
    }
}
