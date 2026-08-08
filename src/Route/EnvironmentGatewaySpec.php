<?php

declare(strict_types=1);

namespace Cbox\Platform\Route;

/**
 * The ingress for one environment: which hostnames it terminates, and who
 * issues their certificates.
 *
 * Per ENVIRONMENT, and that is the load-bearing choice. Per service would mean
 * a load balancer each, which is real money in the customer's own account. One
 * shared Gateway for the whole tenant would mean every service deploy rewriting
 * an object every other service also owns — a single object with many writers
 * and no arbiter. An environment is the smallest boundary where the set of
 * hostnames is already known in one place.
 */
readonly class EnvironmentGatewaySpec
{
    /**
     * @param  list<string>  $domains
     */
    public function __construct(
        public string $environmentId,
        public string $organizationId,
        public string $namespace,
        public array $domains,
        public string $acmeServer,
        public string $acmeEmail,
    ) {}

    public function gatewayName(): string
    {
        return 'cortex-gateway';
    }

    public function issuerName(): string
    {
        return 'cortex-acme';
    }

    /**
     * A listener name has to be a valid DNS label and unique within the
     * Gateway, and a hostname is neither — so it is hashed rather than
     * sanitised. Sanitising collapses `a.example.com` and `a-example.com` onto
     * the same name, and the second listener silently replaces the first.
     */
    public function listenerName(string $domain): string
    {
        return 'https-'.substr(hash('sha256', $domain), 0, 12);
    }

    public function certificateSecret(string $domain): string
    {
        return 'tls-'.substr(hash('sha256', $domain), 0, 12);
    }
}
