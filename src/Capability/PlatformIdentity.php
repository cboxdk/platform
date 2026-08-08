<?php

declare(strict_types=1);

namespace Cbox\Platform\Capability;

use Cbox\Platform\Manifest\Labels;

/**
 * Who owns the objects this package compiles, and what they are called.
 *
 * WHY THIS IS ONE OBJECT. The product's name was scattered through five
 * compilers as string literals — a `cortex.io/` label prefix, a `cortex-sync`
 * field manager, a `cortex-gateway`, a `cortex-acme`, a `cortex:cluster-reader`.
 * A package two products share cannot have one product's name baked into what it
 * emits, and a rename spread over five files is a rename that gets done four
 * times.
 *
 * THE LABEL PREFIX MUST BE A DOMAIN YOU CONTROL. It is a DNS subdomain, and the
 * convention exists so two vendors cannot collide inside one object. The default
 * is a Cbox domain; the previous `cortex.io` was not one, and there is a real
 * company on that name.
 *
 * THE FIELD MANAGER IS LOAD-BEARING. Server-side apply records field ownership
 * under it, so changing it on a cluster that already has objects orphans every
 * field the platform owns — the old manager keeps them and the new one cannot
 * take them back without a forced conflict. Choose it once per installation and
 * leave it alone.
 */
readonly class PlatformIdentity
{
    public function __construct(
        /** A DNS subdomain you control. Prefixes every label this package owns. */
        public string $labelPrefix = 'platform.cbox.dk',
        /** The server-side-apply field manager. See the warning above. */
        public string $fieldManager = 'cbox-platform',
        /** Prefixes the objects the platform owns rather than the customer. */
        public string $resourcePrefix = 'cbox',
    ) {}

    /** A label key under this installation's prefix. */
    public function label(string $suffix): string
    {
        return $this->labelPrefix.'/'.$suffix;
    }

    /** The name of an object the platform owns, e.g. `cbox-gateway`. */
    public function name(string $suffix): string
    {
        return $this->resourcePrefix.'-'.$suffix;
    }

    /** A cluster-scoped role name, which is conventionally colon-separated. */
    public function role(string $suffix): string
    {
        return $this->resourcePrefix.':'.$suffix;
    }

    /**
     * The label set for one object.
     *
     * @param  array<string, string>  $identity  suffix => value, e.g. `service` => id
     * @return array<string, string>
     */
    public function labels(
        string $name,
        array $identity,
        ?string $component = null,
        ?string $version = null,
        ?string $instance = null,
        ?string $partOf = null,
    ): array {
        $labels = [$this->label('managed') => 'true'];

        foreach ($identity as $suffix => $value) {
            $labels[$this->label($suffix)] = $value;
        }

        if ($name !== '') {
            $labels['app.kubernetes.io/name'] = $name;
        }

        // Omitted rather than emitted empty or invalid. A label Kubernetes
        // refuses fails the whole apply, and one carrying a placeholder is a
        // fact nobody stated — `version: unknown` reads as a version.
        foreach ([
            'app.kubernetes.io/instance' => $instance,
            'app.kubernetes.io/version' => $version,
            'app.kubernetes.io/component' => $component,
            'app.kubernetes.io/part-of' => $partOf,
        ] as $key => $value) {
            if ($value !== null && Labels::isValidValue($value)) {
                $labels[$key] = $value;
            }
        }

        $labels['app.kubernetes.io/managed-by'] = $this->fieldManager;

        return $labels;
    }
}
