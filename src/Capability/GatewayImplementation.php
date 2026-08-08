<?php

declare(strict_types=1);

namespace Cbox\Platform\Capability;

use LogicException;

/**
 * Which Gateway API implementation is installed, and what it can be told.
 *
 * THE SECOND THING A LOCAL CLUSTER CANNOT INHERIT. `gatewayClassName` was the
 * literal string `cortex`, so a Gateway compiled for anywhere else names a class
 * no controller has claimed: the object applies cleanly, no address is ever
 * assigned, and no traffic flows. Nothing reports an error, because nothing is
 * wrong — there is simply no controller listening.
 *
 * The class name is one half. The other is that **PROXY protocol and client-IP
 * detection are configured through a vendor CRD**, not through the Gateway API,
 * which has no vocabulary for either. Envoy Gateway's `ClientTrafficPolicy` is
 * what preserves the client's real address; a different implementation does not
 * have that object at all, and emitting it would fail the apply against a
 * cluster whose CRD does not exist.
 *
 * So the implementation is named, and what gets compiled follows from it.
 */
readonly class GatewayImplementation
{
    private function __construct(
        public string $className,
        public bool $hasEnvoyClientTrafficPolicy,
        /**
         * Alpha, and therefore free to change under you. Named here so an
         * installation on a newer Envoy Gateway sets one value rather than
         * waiting for a release of this package.
         */
        public string $clientTrafficPolicyApiVersion = 'gateway.envoyproxy.io/v1alpha1',
    ) {}

    /**
     * Envoy Gateway: the client's real address is preserved through PROXY
     * protocol, and `X-Forwarded-For` is trusted exactly one hop.
     */
    public static function envoyGateway(
        string $className = 'cbox',
        string $clientTrafficPolicyApiVersion = 'gateway.envoyproxy.io/v1alpha1',
    ): self {
        return new self(
            self::named($className),
            hasEnvoyClientTrafficPolicy: true,
            clientTrafficPolicyApiVersion: $clientTrafficPolicyApiVersion,
        );
    }

    /**
     * Any other conformant implementation.
     *
     * Routing works; the client-address handling does not, because there is no
     * portable way to ask for it. An application behind this sees the proxy's
     * address, and that is a real behavioural difference rather than a detail —
     * so it is chosen by name rather than fallen back into.
     */
    public static function conformant(string $className): self
    {
        return new self(self::named($className), hasEnvoyClientTrafficPolicy: false);
    }

    private static function named(string $className): string
    {
        if ($className === '') {
            throw new LogicException(
                'A Gateway needs a class name. An empty one compiles an object no controller '
                .'claims: it applies, never gets an address, and never serves traffic.'
            );
        }

        return $className;
    }
}
