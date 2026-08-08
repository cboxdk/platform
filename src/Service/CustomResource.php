<?php

declare(strict_types=1);

namespace Cbox\Platform\Service;

use Cbox\Platform\Compile\ServiceCompiler;
use LogicException;

/**
 * An object the platform does not model, deployed alongside a service.
 *
 * A SealedSecret, a Crossplane claim, a NetworkPolicy, some operator's CR. The
 * platform has no opinion about what it means — it carries it, labels it, plans
 * it, and prunes it with the service it belongs to.
 *
 * WHY CARRY IT AT ALL, when a customer could `kubectl apply` it themselves: so
 * that it participates. An object applied by hand is invisible to plan/diff, is
 * never pruned when the service goes, and is refused by the admission policy the
 * moment it collides with something managed. Carried here it is part of the same
 * desired set as everything else.
 *
 * THE BODY IS OPAQUE AND THE METADATA IS NOT. Whatever `spec` holds is the
 * customer's business. Its namespace, its managed labels and its name are the
 * platform's, because those are what make it a managed object rather than
 * something that merely looks like one — see {@see ServiceCompiler}.
 */
readonly class CustomResource
{
    /**
     * @param  array<string, mixed>  $body  the object as written, minus the metadata the platform owns
     */
    public function __construct(
        public string $apiVersion,
        public string $kind,
        public string $name,
        public array $body = [],
    ) {
        if ($apiVersion === '' || $kind === '' || $name === '') {
            throw new LogicException(
                'A custom resource needs an apiVersion, a kind and a name. '
                .'An object missing any of them cannot be applied, planned or pruned.'
            );
        }
    }

    /** `Kind/name`, the identity a plan and a prune use. */
    public function key(): string
    {
        return $this->kind.'/'.$this->name;
    }

    /** The API group, or an empty string for the core group. */
    public function group(): string
    {
        $slash = strpos($this->apiVersion, '/');

        return $slash === false ? '' : substr($this->apiVersion, 0, $slash);
    }
}
