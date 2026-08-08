<?php

declare(strict_types=1);

namespace Cbox\Platform\Binding;

/**
 * One resolved binding, as the compiler needs it: which env names get which
 * fields, and where in the cluster those fields come from.
 *
 * Resolved in the spec factory rather than the compiler, like every other
 * lookup — the compiler stays a pure function of its input and never touches
 * Eloquent.
 */
readonly class BindingSpec
{
    /**
     * @param  list<array{field: ConnectionField, name: string}>  $map
     */
    public function __construct(
        public string $databaseName,
        public string $engine,
        public array $map,
        public ConnectionSource $source,
    ) {}
}
