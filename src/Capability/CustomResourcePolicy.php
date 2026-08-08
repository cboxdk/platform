<?php

declare(strict_types=1);

namespace Cbox\Platform\Capability;

use Cbox\Platform\Service\CustomResource;

/**
 * What a customer may deploy that the platform does not model.
 *
 * THE POLICY IS THE CONSUMER'S, NOT THIS PACKAGE'S. A hosted multi-tenant
 * control plane has to be strict about what lands in a shared cluster. A
 * developer running their own kind cluster on their own laptop owns the whole
 * thing and should not have to ask a library for permission. Baking one of those
 * answers in would make the package one product's, which is the failure this
 * whole design exists to avoid.
 *
 * So the package carries custom resources, stamps them as managed, and enforces
 * the invariants that make them *managed objects* rather than objects that
 * merely look like it. Which kinds are allowed at all is a value the consumer
 * supplies.
 *
 * WHAT IS NOT NEGOTIABLE, whatever the policy says:
 *
 *   - the namespace is the environment's, always. "Belongs to this service" is
 *     what carrying it means, and a resource that could name its own namespace
 *     is a tenancy escape wearing a feature's clothes;
 *   - the platform's labels are the platform's. A customer object carrying the
 *     managed label would impersonate one, and the admission policy in the
 *     tenant keys on exactly that label to decide who may write;
 *   - a name already used by a compiled object is refused, rather than one of
 *     them silently overwriting the other.
 *
 * Those live in the compiler, not here, because they are not policy.
 */
readonly class CustomResourcePolicy
{
    /**
     * @param  list<string>  $allowedGroups  API groups a resource may come from;
     *                                       `*` allows any
     */
    private function __construct(
        public array $allowedGroups,
    ) {}

    /**
     * The default: a service carries no custom resources at all.
     *
     * Deny-by-default, because a library that shipped an open door would put
     * every consumer one forgotten configuration away from arbitrary objects in
     * a shared cluster.
     */
    public static function forbidden(): self
    {
        return new self([]);
    }

    /**
     * Any group. For a cluster whose operator is the person deploying to it —
     * a laptop, a CI sandbox, a single-tenant installation.
     */
    public static function unrestricted(): self
    {
        return new self(['*']);
    }

    /**
     * Only these API groups.
     *
     * By GROUP rather than by kind, because a group is the unit an operator is
     * installed as: allowing `bitnami.com` is a decision about trusting sealed
     * secrets, while listing kinds one at a time drifts the moment the operator
     * ships another one.
     *
     * @param  list<string>  $groups
     */
    public static function allowingGroups(array $groups): self
    {
        return new self(array_values(array_filter(
            $groups,
            static fn (string $group): bool => $group !== '',
        )));
    }

    /** Whether this installation permits no custom resources at all. */
    public function allowsNothing(): bool
    {
        return $this->allowedGroups === [];
    }

    public function allows(CustomResource $resource): bool
    {
        if ($this->allowedGroups === []) {
            return false;
        }

        return in_array('*', $this->allowedGroups, true)
            || in_array($resource->group(), $this->allowedGroups, true);
    }

    /** Why a resource was refused, for an error a person can act on. */
    public function refusalFor(CustomResource $resource): string
    {
        if ($this->allowedGroups === []) {
            return "This installation does not allow custom resources, so [{$resource->key()}] cannot be deployed.";
        }

        $group = $resource->group() === '' ? 'the core group' : "[{$resource->group()}]";

        return "[{$resource->key()}] comes from {$group}, which this installation does not allow. "
            .'Allowed: '.implode(', ', $this->allowedGroups).'.';
    }
}
