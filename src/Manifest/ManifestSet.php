<?php

declare(strict_types=1);

namespace Cbox\Platform\Manifest;

/**
 * The full compiled output for one service — ordered, deterministic.
 */
readonly class ManifestSet
{
    /**
     * @param  list<Manifest>  $manifests
     */
    public function __construct(
        public array $manifests,
    ) {}

    /**
     * Content hashes keyed by manifest identity — the shape persisted as
     * `applied_hashes` and diffed by the planner.
     *
     * @return array<string, string>
     */
    public function hashes(): array
    {
        $hashes = [];

        foreach ($this->manifests as $manifest) {
            $hashes[$manifest->key()] = $manifest->hash();
        }

        return $hashes;
    }

    /** Multi-document YAML — what the bridge server-side-applies. */
    public function toYaml(): string
    {
        return implode("---\n", array_map(
            static fn (Manifest $manifest): string => $manifest->toYaml(),
            $this->manifests,
        ));
    }
}
