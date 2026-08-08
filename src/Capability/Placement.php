<?php

declare(strict_types=1);

namespace Cbox\Platform\Capability;

/**
 * Where a workload runs — which is the platform's business, not the
 * application's.
 *
 * AN APPLICATION AND ITS PLACEMENT ARE TWO DIFFERENT DESIGNS. What a service IS
 * — its image, its processes, its bindings, how it scales — is authored by the
 * customer and is identical whether it runs on a laptop or in a cell. Where its
 * pods land is a property of the cluster underneath: a single-node kind cluster
 * has nowhere to spread to, a cell has hosts and zones, and a dedicated node
 * pool needs a toleration the application has never heard of.
 *
 * So this sits on the target, beside the snapshot runtime and the certificate
 * source, and not in `ServiceSpec`. A customer who could set node affinity
 * would be authoring against a topology they cannot see and that differs
 * between the two places their application runs — which is exactly the split
 * this package exists to prevent.
 *
 * The defaults reproduce what the compiler used to hardcode: spread across
 * hosts, and never refuse to schedule over it.
 */
readonly class Placement
{
    /**
     * @param  string  $topologyKey  the node label pods are spread across
     * @param  bool  $strict  refuse to schedule rather than tolerate an uneven
     *                        spread. False everywhere sensible: a single-node
     *                        cluster satisfies nothing, and a workload that
     *                        will not start is worse than one that is unevenly
     *                        placed
     * @param  array<string, string>  $nodeSelector  nodes a workload may use at all
     * @param  list<array<string, mixed>>  $tolerations  taints it may schedule over
     */
    public function __construct(
        public string $topologyKey = 'kubernetes.io/hostname',
        public int $maxSkew = 1,
        public bool $strict = false,
        public array $nodeSelector = [],
        public array $tolerations = [],
    ) {}

    /**
     * Nowhere to spread to, so do not ask.
     *
     * A single-node cluster — kind, or a one-machine cell — satisfies a spread
     * constraint trivially, and carrying one is noise in every object. The
     * compiled shape differs; the application does not.
     */
    public static function singleNode(): self
    {
        return new self(topologyKey: '');
    }

    public function spreads(): bool
    {
        return $this->topologyKey !== '';
    }

    /**
     * The `topologySpreadConstraints` entry, for pods matching this selector.
     *
     * @param  array<string, string>  $selector
     * @return list<array<string, mixed>>
     */
    public function constraints(array $selector): array
    {
        if (! $this->spreads()) {
            return [];
        }

        return [[
            'maxSkew' => $this->maxSkew,
            'topologyKey' => $this->topologyKey,
            'whenUnsatisfiable' => $this->strict ? 'DoNotSchedule' : 'ScheduleAnyway',
            'labelSelector' => ['matchLabels' => $selector],
        ]];
    }

    /**
     * What this placement adds to a pod spec, if anything.
     *
     * @param  array<string, string>  $selector
     * @return array<string, mixed>
     */
    public function podFields(array $selector): array
    {
        $fields = [];

        $constraints = $this->constraints($selector);

        if ($constraints !== []) {
            $fields['topologySpreadConstraints'] = $constraints;
        }

        if ($this->nodeSelector !== []) {
            $fields['nodeSelector'] = $this->nodeSelector;
        }

        if ($this->tolerations !== []) {
            $fields['tolerations'] = $this->tolerations;
        }

        return $fields;
    }
}
