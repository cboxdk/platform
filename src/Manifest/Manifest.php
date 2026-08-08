<?php

declare(strict_types=1);

namespace Cbox\Platform\Manifest;

use Symfony\Component\Yaml\Yaml;

/**
 * One compiled Kubernetes object. The nested `body` array is the
 * serialization edge — manifests exist to be serialized, so the array shape
 * is the honest representation here and nowhere else.
 */
readonly class Manifest
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public string $apiVersion,
        public string $kind,
        public string $name,
        public string $namespace,
        public array $body,
    ) {}

    /**
     * The object as plain data, for a consumer that retains what it applied.
     *
     * Retaining bodies is optional and deliberately so — hashes are enough to
     * plan, and a vendored addon set runs to megabytes — but a consumer that
     * does keep them gets a field-level diff out of it.
     *
     * @return array{apiVersion: string, kind: string, name: string, namespace: string, body: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'apiVersion' => $this->apiVersion,
            'kind' => $this->kind,
            'name' => $this->name,
            'namespace' => $this->namespace,
            'body' => $this->body,
        ];
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    public static function fromArray(array $stored): self
    {
        $string = static fn (string $key): string => is_string($stored[$key] ?? null) ? $stored[$key] : '';

        /** @var array<string, mixed> $body */
        $body = is_array($stored['body'] ?? null) ? $stored['body'] : [];

        return new self(
            apiVersion: $string('apiVersion'),
            kind: $string('kind'),
            name: $string('name'),
            namespace: $string('namespace'),
            body: $body,
        );
    }

    /** Stable identity within a service's manifest set. */
    public function key(): string
    {
        return $this->kind.'/'.$this->name;
    }

    /**
     * Content hash for plan/diff. Canonical JSON (sorted keys, recursive) so
     * semantically identical manifests always hash identically.
     */
    public function hash(): string
    {
        return hash('sha256', json_encode(self::canonicalize($this->body), JSON_THROW_ON_ERROR));
    }

    public function toYaml(): string
    {
        return Yaml::dump($this->body, 10, 2);
    }

    /**
     * Sort maps by key, leave lists in order, all the way down.
     *
     * Keyed on array-key rather than string because it recurses into BOTH:
     * a manifest is maps of lists of maps, and typing the parameter as a
     * string-keyed array made the list branch provably dead — phpstan said
     * so, and it was right.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function canonicalize(array $value): array
    {
        $isList = array_is_list($value);

        if (! $isList) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonicalize($item);
            }
        }

        return $value;
    }

    /**
     * A nested map out of the body, or an empty one.
     *
     * Two compilers reach into a Deployment's pod spec to build a Job from it,
     * and `body` is `array<string, mixed>` — so every step of
     * `['spec']['template']['spec']` is an offset on an unknown. Walking it
     * once here with the checks in place beats casting at each hop, where a
     * malformed manifest would produce a Job with no container and an apply
     * that succeeds.
     *
     * @return array<array-key, mixed>
     */
    public function map(string ...$path): array
    {
        /** @var array<array-key, mixed> $cursor */
        $cursor = $this->body;

        foreach ($path as $key) {
            $next = $cursor[$key] ?? null;

            if (! is_array($next)) {
                return [];
            }

            $cursor = $next;
        }

        return $cursor;
    }

    /**
     * One entry of a list nested in the body, as a map.
     *
     * @return array<string, mixed>
     */
    public function listItem(int $index, string ...$path): array
    {
        $list = $this->map(...$path);
        $item = $list[$index] ?? null;

        if (! is_array($item)) {
            return [];
        }

        /** @var array<string, mixed> $item */
        return $item;
    }
}
