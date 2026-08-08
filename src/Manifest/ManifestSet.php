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

    /**
     * The set as plain data, keyed by object identity.
     *
     * @return array<string, array<string, mixed>>
     */
    public function toArray(): array
    {
        $stored = [];

        foreach ($this->manifests as $manifest) {
            $stored[$manifest->key()] = $manifest->toArray();
        }

        return $stored;
    }

    /**
     * @param  array<string, mixed>  $stored  as returned by {@see self::toArray()}
     */
    public static function fromArray(array $stored): self
    {
        $manifests = [];

        foreach ($stored as $entry) {
            if (is_array($entry)) {
                /** @var array<string, mixed> $entry */
                $manifests[] = Manifest::fromArray($entry);
            }
        }

        return new self($manifests);
    }

    /** One object by its `Kind/name` identity, or null. */
    public function find(string $key): ?Manifest
    {
        foreach ($this->manifests as $manifest) {
            if ($manifest->key() === $key) {
                return $manifest;
            }
        }

        return null;
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
