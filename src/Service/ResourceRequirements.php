<?php

declare(strict_types=1);

namespace Cbox\Platform\Service;

/**
 * What a workload asks of a node, and what it may not exceed.
 *
 * REQUESTS ARE NOT A TUNING DETAIL. The scheduler places pods by request, so a
 * container without one is scheduled as if it were free — every replica lands
 * wherever there is space, and a node accepts work it cannot run. And an
 * autoscaler targeting CPU *utilization* is a percentage OF THE REQUEST: with
 * no request there is no denominator, the metric reports as unknown, and the
 * workload never scales. That is not a theoretical failure; it is what
 * `autoscaleCpuPercent` did on every service that set it, silently, because
 * nothing here emitted a request.
 *
 * LIMITS ARE A SEPARATE DECISION and deliberately optional. A CPU limit
 * throttles rather than kills, which usually makes a service slower for no
 * benefit; a memory limit is what stops one workload taking a node down with
 * it. Absent means absent — no field is emitted, and the namespace's own
 * defaults apply.
 *
 * Values are Kubernetes quantities as written: `'250m'`, `'1'`, `'512Mi'`,
 * `'2Gi'`. Kept as strings rather than parsed into numbers because Kubernetes
 * owns that grammar, and a round-trip through a float is how `1.5Gi` becomes
 * something else.
 */
readonly class ResourceRequirements
{
    public function __construct(
        public ?string $cpuRequest = null,
        public ?string $memoryRequest = null,
        public ?string $cpuLimit = null,
        public ?string $memoryLimit = null,
    ) {}

    /**
     * What a service asks for when nobody has said.
     *
     * These exact values were inline in the compiler before this class existed,
     * so this is where they moved rather than something new: a small request
     * that lets the scheduler place the pod and gives a CPU autoscaler the
     * denominator it needs, with no limit, so a burst is not throttled.
     */
    public static function defaults(): self
    {
        return new self(cpuRequest: '100m', memoryRequest: '128Mi');
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    public static function fromArray(array $stored): self
    {
        $value = static fn (string $key): ?string => isset($stored[$key])
            && is_string($stored[$key]) && trim($stored[$key]) !== ''
            ? trim($stored[$key])
            : null;

        return new self(
            cpuRequest: $value('cpu_request'),
            memoryRequest: $value('memory_request'),
            cpuLimit: $value('cpu_limit'),
            memoryLimit: $value('memory_limit'),
        );
    }

    public function hasCpuRequest(): bool
    {
        return $this->cpuRequest !== null;
    }

    public function isEmpty(): bool
    {
        return $this->cpuRequest === null
            && $this->memoryRequest === null
            && $this->cpuLimit === null
            && $this->memoryLimit === null;
    }

    /**
     * The `resources` block, or nothing at all.
     *
     * Empty rather than a block of nulls: a container with
     * `resources: {requests: {}}` reads as configured and is not, and it is the
     * kind of difference that shows up in a plan as a change nobody made.
     *
     * @return array<string, array<string, string>>
     */
    public function toArray(): array
    {
        $requests = array_filter([
            'cpu' => $this->cpuRequest,
            'memory' => $this->memoryRequest,
        ], static fn (?string $value): bool => $value !== null);

        $limits = array_filter([
            'cpu' => $this->cpuLimit,
            'memory' => $this->memoryLimit,
        ], static fn (?string $value): bool => $value !== null);

        return array_filter([
            'requests' => $requests,
            'limits' => $limits,
        ], static fn (array $block): bool => $block !== []);
    }
}
