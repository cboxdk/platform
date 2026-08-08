<?php

declare(strict_types=1);

namespace Cbox\Platform\Compile;

use Cbox\Platform\Capability\CertificateSource;
use Cbox\Platform\Capability\PlatformTarget;
use Cbox\Platform\Contracts\GatewayCompiler;
use Cbox\Platform\Manifest\Manifest;
use Cbox\Platform\Manifest\ManifestSet;
use Cbox\Platform\Route\EnvironmentGatewaySpec;

/**
 * The environment's ingress, with TLS.
 *
 * Every application Cortex deployed was served over plain HTTP: the Gateway
 * came from a static manifest with one `protocol: HTTP, port: 80` listener and
 * nothing compiled a certificate. A customer's login form went over the wire in
 * the clear.
 *
 * The Gateway is now derived from the services that declare a domain, so a
 * hostname cannot be routed without also being terminated — the two facts have
 * one source instead of two that drift.
 *
 * Certificates come from cert-manager over ACME. Unlike the cell's SNI gateway,
 * which is a TCP proxy and therefore cannot answer an HTTP-01 challenge, this
 * Gateway terminates TLS itself and can — so a public certificate needs no DNS
 * provider credential anywhere in the system.
 */
class EnvironmentGatewayCompiler implements GatewayCompiler
{
    public function __construct(private readonly PlatformTarget $target = new PlatformTarget) {}

    public function compile(EnvironmentGatewaySpec $spec): ManifestSet
    {
        // No domains, no ingress. An environment of workers and cron jobs
        // should not be paying for a load balancer nothing routes to.
        if ($spec->domains === []) {
            return new ManifestSet([]);
        }

        // The issuer first: a Certificate naming an Issuer that does not exist
        // sits in `IssuerNotFound` forever rather than failing, so the order is
        // the difference between a slow issue and one that never happens.
        $manifests = [$this->issuer($spec)];

        // Certificates next: a Gateway listener whose Secret does not exist
        // is Programmed=False, and Envoy will not serve the listener at all —
        // so the HTTP listener beside it is the only thing answering, which
        // looks exactly like TLS never having been configured.
        foreach ($spec->domains as $domain) {
            $manifests[] = $this->certificate($spec, $domain);
        }

        $manifests[] = $this->gateway($spec);

        // And the policy that makes the client's own address survive the trip —
        // which only exists on Envoy Gateway. The Gateway API has no portable
        // vocabulary for PROXY protocol or client-IP detection, so on any other
        // implementation this object's CRD is simply not installed and emitting
        // it would fail the whole apply. Routing still works; the application
        // sees the proxy's address instead of the client's.
        if ($this->target->gateway->hasEnvoyClientTrafficPolicy) {
            $manifests[] = $this->clientTrafficPolicy($spec);
        }

        return new ManifestSet($manifests);
    }

    /**
     * @return array<string, string>
     */
    /**
     * @return array<string, string>
     */
    /**
     * The Gateway is an object the PLATFORM owns, not the customer, so the
     * installation's prefix names it — not a literal that spells one product.
     */
    private function gatewayName(): string
    {
        return $this->target->identity->name('gateway');
    }

    private function issuerName(): string
    {
        return $this->target->identity->name('acme');
    }

    /**
     * @return array<string, string>
     */
    private function labels(EnvironmentGatewaySpec $spec): array
    {
        return $this->target->identity->labels(
            name: '',
            identity: [
                'organization' => $spec->organizationId,
                'environment' => $spec->environmentId,
            ],
            component: 'gateway',
        );
    }

    /**
     * The ACME issuer for this environment.
     *
     * Per environment rather than one for the cluster, because an HTTP-01
     * solver has to name the Gateway it answers challenges through — and there
     * is one of those per environment. A ClusterIssuer would have to enumerate
     * every environment's Gateway, and would be wrong the moment one was added.
     *
     * The solver attaches a temporary HTTPRoute to the same Gateway the
     * certificate is for, which is why the plain HTTP listener has to stay:
     * ACME dials port 80.
     */
    private function issuer(EnvironmentGatewaySpec $spec): Manifest
    {
        return new Manifest(
            apiVersion: 'cert-manager.io/v1',
            kind: 'Issuer',
            name: $this->issuerName(),
            namespace: $spec->namespace,
            body: [
                'apiVersion' => 'cert-manager.io/v1',
                'kind' => 'Issuer',
                'metadata' => [
                    'name' => $this->issuerName(),
                    'namespace' => $spec->namespace,
                    'labels' => $this->labels($spec),
                ],
                'spec' => $this->issuerSpec($spec),
            ],
        );
    }

    /**
     * The one part of TLS that differs between targets.
     *
     * The Certificate objects are identical either way — same hostnames, same
     * Secret names, same Gateway. Only who signs them changes, which is why
     * this is the only method the capability reaches.
     *
     * @return array<string, mixed>
     */
    private function issuerSpec(EnvironmentGatewaySpec $spec): array
    {
        $certificates = $this->target->certificates;

        if ($certificates->source === CertificateSource::SelfSigned) {
            return ['selfSigned' => new \stdClass];
        }

        if ($certificates->source === CertificateSource::CertificateAuthority) {
            return ['ca' => ['secretName' => $certificates->caSecretName]];
        }

        $acme = [
            'server' => $certificates->acmeServer,
            'privateKeySecretRef' => ['name' => $this->issuerName().'-account'],
            'solvers' => [[
                'http01' => ['gatewayHTTPRoute' => [
                    'parentRefs' => [[
                        'name' => $this->gatewayName(),
                        'namespace' => $spec->namespace,
                        'kind' => 'Gateway',
                    ]],
                ]],
            ]],
        ];

        // Let's Encrypt accepts a registration without one, and an operator
        // who has supplied an address gets expiry warnings — so it is included
        // when set and omitted rather than sent empty when not.
        if ($certificates->acmeEmail !== '') {
            $acme['email'] = $certificates->acmeEmail;
        }

        return ['acme' => $acme];
    }

    private function certificate(EnvironmentGatewaySpec $spec, string $domain): Manifest
    {
        $name = $spec->certificateSecret($domain);

        return new Manifest(
            apiVersion: 'cert-manager.io/v1',
            kind: 'Certificate',
            name: $name,
            namespace: $spec->namespace,
            body: [
                'apiVersion' => 'cert-manager.io/v1',
                'kind' => 'Certificate',
                'metadata' => [
                    'name' => $name,
                    'namespace' => $spec->namespace,
                    'labels' => $this->labels($spec),
                ],
                'spec' => [
                    'secretName' => $name,
                    'dnsNames' => [$domain],
                    'privateKey' => ['algorithm' => 'ECDSA', 'size' => 256],
                    'issuerRef' => [
                        'name' => $this->issuerName(),
                        // Namespaced, not a ClusterIssuer: the solver below
                        // names the Gateway it answers challenges through, and
                        // that Gateway is this environment's.
                        'kind' => 'Issuer',
                        'group' => 'cert-manager.io',
                    ],
                ],
            ],
        );
    }

    /**
     * How the real client's address reaches the customer's application.
     *
     * WITHOUT THIS IT DOES NOT, and nothing fails — the application simply sees
     * the load balancer's address on every request. Rate limiting by IP,
     * geo-routing, abuse blocking and audit logs all keep working and all
     * become wrong, which is the worst way for a thing to be broken.
     *
     * Hetzner's load balancer NATs, so the source address is already gone by
     * the time Envoy sees the connection. PROXY protocol is what carries it,
     * and `externalTrafficPolicy: Local` — which is set for its own reasons —
     * does not substitute for it.
     *
     * THE TWO HALVES ARE ONE CHANGE. This tells Envoy to read a PROXY header;
     * the `uses-proxyprotocol` annotation on the EnvoyProxy's Service tells the
     * load balancer to send one. They are applied by different controllers, at
     * different times, and a strict listener breaks every request in the gap —
     * Envoy waits for a header that is not coming yet, or the load balancer
     * prefixes bytes Envoy reads as a malformed request.
     *
     * `optional: true` is what makes that gap survivable: the listener accepts
     * connections with the header and without it, so the order the two halves
     * land in stops mattering. The cost is that something able to open a
     * connection to the Envoy pod DIRECTLY could state an address of its
     * choosing — inside the customer's own cluster, on a path where the load
     * balancer is the only ingress. A rollout that drops every request is the
     * larger risk, and it is certain rather than hypothetical.
     *
     * `numTrustedHops: 1` counts Envoy itself and nothing beyond it. Counting
     * hops that do not exist is worse than having no header: it would let a
     * client state its own address by sending X-Forwarded-For, and the result
     * looks trustworthy.
     */
    private function clientTrafficPolicy(EnvironmentGatewaySpec $spec): Manifest
    {
        $name = $this->gatewayName().'-client';

        return new Manifest(
            apiVersion: $this->target->gateway->clientTrafficPolicyApiVersion,
            kind: 'ClientTrafficPolicy',
            name: $name,
            namespace: $spec->namespace,
            body: [
                'apiVersion' => $this->target->gateway->clientTrafficPolicyApiVersion,
                'kind' => 'ClientTrafficPolicy',
                'metadata' => [
                    'name' => $name,
                    'namespace' => $spec->namespace,
                    'labels' => $this->labels($spec),
                ],
                'spec' => [
                    // Targets this environment's Gateway, not the class: the
                    // Gateway is compiled per environment, so the policy that
                    // configures it has to be too.
                    'targetRefs' => [[
                        'group' => 'gateway.networking.k8s.io',
                        'kind' => 'Gateway',
                        'name' => $this->gatewayName(),
                    ]],
                    // `proxyProtocol`, not `enableProxyProtocol`. The older
                    // field is deprecated — the tenant's own admission warned
                    // about it — and it cannot express `optional`, which is the
                    // property that makes this safe to roll onto a cluster that
                    // is already serving traffic.
                    'proxyProtocol' => ['optional' => true],
                    'clientIPDetection' => [
                        'xForwardedFor' => [
                            // Envoy is the first and only proxy in front of the
                            // application, so the address it observed — the one
                            // PROXY protocol just gave it — is the client's.
                            'numTrustedHops' => 1,
                        ],
                    ],
                ],
            ],
        );
    }

    private function gateway(EnvironmentGatewaySpec $spec): Manifest
    {
        // Port 80 stays, and is not a fallback for people who did not get
        // around to TLS: ACME's HTTP-01 challenge is answered on it, so
        // removing it means no certificate is ever issued and every HTTPS
        // listener below stays unprogrammed.
        $listeners = [[
            'name' => 'http',
            'protocol' => 'HTTP',
            'port' => 80,
            'allowedRoutes' => ['namespaces' => ['from' => 'Same']],
        ]];

        foreach ($spec->domains as $domain) {
            $listeners[] = [
                'name' => $spec->listenerName($domain),
                'protocol' => 'HTTPS',
                'port' => 443,
                // Per hostname rather than one listener with many certificate
                // refs: SNI selection across refs is implementation-defined,
                // and a listener that names its hostname says what it serves.
                'hostname' => $domain,
                'tls' => [
                    'mode' => 'Terminate',
                    'certificateRefs' => [[
                        'kind' => 'Secret',
                        'name' => $spec->certificateSecret($domain),
                    ]],
                ],
                'allowedRoutes' => ['namespaces' => ['from' => 'Same']],
            ];
        }

        return new Manifest(
            apiVersion: 'gateway.networking.k8s.io/v1',
            kind: 'Gateway',
            name: $this->gatewayName(),
            namespace: $spec->namespace,
            body: [
                'apiVersion' => 'gateway.networking.k8s.io/v1',
                'kind' => 'Gateway',
                'metadata' => [
                    'name' => $this->gatewayName(),
                    'namespace' => $spec->namespace,
                    'labels' => $this->labels($spec),
                ],
                'spec' => [
                    'gatewayClassName' => $this->target->gateway->className,
                    'listeners' => $listeners,
                ],
            ],
        );
    }
}
