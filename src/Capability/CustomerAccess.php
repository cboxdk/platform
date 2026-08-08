<?php

declare(strict_types=1);

namespace Cbox\Platform\Capability;

/**
 * What a customer's own kubectl may do, when they have one.
 *
 * BOUND TO GROUPS, NOT PEOPLE. The identity provider stamps the roles a person
 * holds into their token, and the API server turns them into groups; the
 * bindings name the groups. So a team change takes effect at the person's next
 * token rather than when some reconcile notices — in both directions, which is
 * the direction that matters.
 *
 * EMPTY MEANS OFF, and that is the default. With no identity provider
 * configured, no token can name these groups, so a binding would grant nothing
 * and only add an object somebody has to explain later.
 */
readonly class CustomerAccess
{
    /**
     * @param  array<string, string>  $roles  role held in the identity provider => the Kubernetes ClusterRole it is read through
     */
    public function __construct(
        public array $roles = [],
        /**
         * The prefix the API server applies to every username and group.
         *
         * Applied BY KUBERNETES, from its authentication configuration — not
         * taken from the token. Named here because the bindings have to match
         * it, and a mismatch is a customer who authenticates and holds nothing.
         */
        public string $groupPrefix = 'cbox:',
    ) {}

    public function isEnabled(): bool
    {
        return $this->roles !== [];
    }
}
