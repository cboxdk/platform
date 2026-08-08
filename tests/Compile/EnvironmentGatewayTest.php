<?php

declare(strict_types=1);

use Cbox\Platform\Route\EnvironmentGatewaySpec;

/**
 * Every application Cortex deployed was served over plain HTTP: the Gateway
 * came from a static manifest with one `protocol: HTTP, port: 80` listener and
 * nothing compiled a certificate. A customer's login form went over the wire
 * in the clear.
 */
function gatewaySpec(array $domains): EnvironmentGatewaySpec
{
    return new EnvironmentGatewaySpec(
        environmentId: '01J0000000000000000000ENV1',
        organizationId: '01J0000000000000000000ORG1',
        namespace: 'cx-production-db9k2',
        domains: $domains,
    );
}

it('terminates TLS for every hostname the environment serves', function (): void {
    $set = test()->compileGateway(gatewaySpec(['app.acme.example', 'api.acme.example']));

    $gateway = collect($set->manifests)->firstWhere('kind', 'Gateway');
    $listeners = collect($gateway->body['spec']['listeners']);

    $https = $listeners->where('protocol', 'HTTPS');

    expect($https)->toHaveCount(2)
        ->and($https->pluck('hostname')->sort()->values()->all())
        ->toBe(['api.acme.example', 'app.acme.example'])
        ->and($https->every(fn (array $l): bool => $l['tls']['mode'] === 'Terminate'))->toBeTrue();
});

it('keeps port 80, because that is where ACME answers', function (): void {
    $set = test()->compileGateway(gatewaySpec(['app.acme.example']));
    $listeners = collect(collect($set->manifests)->firstWhere('kind', 'Gateway')->body['spec']['listeners']);

    // Not a fallback for people who did not get around to TLS: removing it
    // means the HTTP-01 challenge is unanswerable, no certificate is ever
    // issued, and every HTTPS listener stays unprogrammed.
    expect($listeners->firstWhere('protocol', 'HTTP')['port'])->toBe(80);
});

it('asks cert-manager for a certificate per hostname, before the Gateway', function (): void {
    $set = test()->compileGateway(gatewaySpec(['app.acme.example']));

    $kinds = array_map(fn ($m): string => $m->kind, $set->manifests);

    // A listener whose Secret does not exist is Programmed=False and Envoy
    // will not serve it at all — which looks exactly like TLS never having
    // been configured.
    expect(array_search('Certificate', $kinds, true))
        ->toBeLessThan(array_search('Gateway', $kinds, true));

    $certificate = collect($set->manifests)->firstWhere('kind', 'Certificate');

    expect($certificate->body['spec']['dnsNames'])->toBe(['app.acme.example'])
        ->and($certificate->body['spec']['issuerRef']['kind'])->toBe('Issuer')
        ->and($certificate->namespace)->toBe('cx-production-db9k2');

    // The listener names the Secret the Certificate writes.
    $listener = collect(collect($set->manifests)->firstWhere('kind', 'Gateway')
        ->body['spec']['listeners'])->firstWhere('protocol', 'HTTPS');

    expect($listener['tls']['certificateRefs'][0]['name'])->toBe($certificate->name);
});

it('compiles nothing for an environment with no hostnames', function (): void {
    // Workers and cron jobs should not be paying for a load balancer nothing
    // routes to.
    expect(test()->compileGateway(gatewaySpec([]))->manifests)->toBe([]);
});

it('does not collapse two hostnames onto one listener name', function (): void {
    // A listener name must be a DNS label and unique within the Gateway, and a
    // hostname is neither. Sanitising rather than hashing maps
    // `a.example.com` and `a-example.com` to the same name, and the second
    // listener silently replaces the first.
    $spec = gatewaySpec(['a.example.com', 'a-example.com']);

    expect($spec->listenerName('a.example.com'))
        ->not->toBe($spec->listenerName('a-example.com'));

    $listeners = collect(test()->compileGateway($spec)->manifests)
        ->firstWhere('kind', 'Gateway')->body['spec']['listeners'];

    expect(collect($listeners)->pluck('name')->unique())->toHaveCount(count($listeners));
});

it('is deterministic', function (): void {
    $a = test()->compileGateway(gatewaySpec(['app.acme.example']));
    $b = test()->compileGateway(gatewaySpec(['app.acme.example']));

    expect($a->toYaml())->toBe($b->toYaml())->and($a->hashes())->toBe($b->hashes());
});

it('issues through the very Gateway it is issuing for', function (): void {
    $set = test()->compileGateway(gatewaySpec(['app.acme.example']));

    $issuer = collect($set->manifests)->firstWhere('kind', 'Issuer');

    // A ClusterIssuer cannot do this: an HTTP-01 solver names the Gateway it
    // answers challenges through, and there is one of those per environment,
    // so a cluster-wide issuer would be wrong the moment an environment was
    // added.
    expect($issuer->namespace)->toBe('cx-production-db9k2');

    $parent = $issuer->body['spec']['acme']['solvers'][0]['http01']['gatewayHTTPRoute']['parentRefs'][0];

    expect($parent['name'])->toBe('cbox-gateway')
        ->and($parent['namespace'])->toBe('cx-production-db9k2');

    // And it comes first: a Certificate naming an Issuer that does not exist
    // sits in IssuerNotFound forever rather than failing.
    $kinds = array_map(fn ($m): string => $m->kind, $set->manifests);
    expect(array_search('Issuer', $kinds, true))
        ->toBeLessThan(array_search('Certificate', $kinds, true));
});

it('omits the ACME email rather than registering an empty one', function (): void {
    $spec = new EnvironmentGatewaySpec(
        environmentId: 'e', organizationId: 'o', namespace: 'ns',
        domains: ['app.acme.example'],
    );

    $issuer = collect(test()->compileGateway($spec)->manifests)
        ->firstWhere('kind', 'Issuer');

    expect($issuer->body['spec']['acme'])->not->toHaveKey('email');
});

it('makes the client\'s own address reach the application', function (): void {
    $set = test()->compileGateway(gatewaySpec(['shop.acme.test']));

    $policy = collect($set->manifests)->firstWhere('kind', 'ClientTrafficPolicy');

    expect($policy)->not->toBeNull();

    // Hetzner's load balancer NATs, so the source address is gone before Envoy
    // sees the connection. Without this every application behind it logs the
    // load balancer on every request — nothing fails, and rate limiting by IP,
    // abuse blocking and audit trails all quietly become wrong.
    expect($policy->body['spec']['proxyProtocol'])->toMatchArray(['optional' => true]);

    // Bound to THIS environment's Gateway. A policy targeting the wrong one
    // configures somebody else's traffic and leaves this one unprotected.
    expect($policy->body['spec']['targetRefs'][0])
        ->toMatchArray(['kind' => 'Gateway', 'name' => 'cbox-gateway']);

    expect($policy->body['metadata']['namespace'])
        ->toBe(collect($set->manifests)->firstWhere('kind', 'Gateway')->body['metadata']['namespace']);
});

it('accepts connections with and without the header, so the two halves can land apart', function (): void {
    $set = test()->compileGateway(gatewaySpec(['shop.acme.test']));
    $policy = collect($set->manifests)->firstWhere('kind', 'ClientTrafficPolicy');

    // Envoy and the load balancer are configured by different controllers at
    // different times. A strict listener drops every request in the gap — a
    // certainty, against the hypothetical risk of something already inside the
    // customer's cluster opening a direct connection to the Envoy pod.
    expect($policy->body['spec']['proxyProtocol']['optional'])->toBeTrue();

    // The deprecated field cannot express that, which is why it is not used.
    expect($policy->body['spec'])->not->toHaveKey('enableProxyProtocol');
});

it('does not tell Envoy to trust a forwarded address nobody set', function (): void {
    $set = test()->compileGateway(gatewaySpec(['shop.acme.test']));
    $policy = collect($set->manifests)->firstWhere('kind', 'ClientTrafficPolicy');

    // numTrustedHops is for a proxy IN FRONT of the load balancer — a CDN.
    // Counting hops that do not exist lets a client forge its own address by
    // sending X-Forwarded-For itself, which is worse than not having the
    // header at all: it looks trustworthy.
    expect($policy->body['spec']['clientIPDetection']['xForwardedFor']['numTrustedHops'])->toBe(1);
});

it('compiles no policy for an environment with no ingress', function (): void {
    // No domains, no load balancer, nothing to preserve an address through.
    expect(test()->compileGateway(gatewaySpec([]))->manifests)->toBe([]);
});
