<?php

declare(strict_types=1);

namespace Cbox\Platform\Capability;

use LogicException;

/**
 * Whether an environment owns its ingress, or attaches to one that already
 * exists.
 *
 * A PROPERTY OF THE SUBSTRATE, never of the application. On a hosted cluster
 * each environment gets its own Gateway: the cluster belongs to one customer,
 * its load balancer is theirs, and an environment that owned nothing could not
 * be torn down cleanly. A local development cluster is the other case entirely
 * — one cluster shared by every project on the machine, one Envoy, and a single
 * set of ports the host can actually reach.
 *
 * That is not a preference. A kind cluster's port mappings are fixed when the
 * cluster is BUILT, so the node ports its gateway publishes have to be pinned to
 * known numbers — and two Services cannot hold the same node port. One shared
 * gateway is the only shape that works, and it emerged from the substrate rather
 * than from anybody's taste.
 *
 * WHAT SHARING CARRIES WITH IT. A gateway nobody in the environment owns also
 * terminates TLS that nobody in the environment owns: its listeners, its
 * certificates and the policy that carries client addresses all belong to
 * whoever installed it. So an environment compiled against a shared gateway
 * emits routes and nothing else, and its routes name the gateway across the
 * namespace boundary.
 *
 * The default is `perEnvironment`, which is what every existing consumer
 * already does.
 */
readonly class GatewayOwnership
{
    private function __construct(
        public bool $shared,
        /** Where the shared gateway lives. Empty when the environment owns one. */
        public string $namespace = '',
        /** What it is called. Empty when the environment owns one. */
        public string $name = '',
    ) {}

    /**
     * The environment compiles its own Gateway, listeners and certificates.
     */
    public static function perEnvironment(): self
    {
        return new self(shared: false);
    }

    /**
     * The environment attaches its routes to a Gateway somebody else installed.
     *
     * Both halves are required and neither is guessable. A route naming a
     * Gateway that does not exist is `Accepted=False` with a reason nobody
     * reads, and a route naming the right Gateway in the wrong namespace is the
     * same thing with a more confusing message.
     */
    public static function shared(string $namespace, string $name): self
    {
        if (trim($namespace) === '' || trim($name) === '') {
            throw new LogicException(
                'A shared gateway needs both a namespace and a name: a route that names the wrong '
                .'one is Accepted=False, which reads as a routing bug rather than a missing object.'
            );
        }

        return new self(shared: true, namespace: $namespace, name: $name);
    }
}
