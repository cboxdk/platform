<?php

declare(strict_types=1);

use Cbox\Platform\Capability\Certificates;
use Cbox\Platform\Capability\PlatformTarget;
use Cbox\Platform\Testing\SpecFactory;

/**
 * TLS is the first thing two real targets cannot agree on.
 *
 * ACME's HTTP-01 challenge needs the authority to reach the hostname from the
 * public internet. A hosted cluster can be reached; a local kind cluster cannot
 * be, at any price — so a local target that inherited the ACME default would
 * compile an Issuer whose orders never validate, and every hostname would sit
 * without TLS while the platform reported the certificate as requested.
 */
function issuerSpec(): array
{
    $set = test()->compileGateway(SpecFactory::gateway(['shop.example.test']));

    $issuer = null;

    foreach ($set->manifests as $manifest) {
        if ($manifest->kind === 'Issuer') {
            $issuer = $manifest;
        }
    }

    expect($issuer)->not->toBeNull();

    return $issuer->body['spec'];
}

it('issues from a public authority by default, which is what every hosted cluster does', function (): void {
    $spec = issuerSpec();

    expect($spec)->toHaveKey('acme')
        ->and($spec['acme']['server'])->toBe('https://acme-staging-v02.api.letsencrypt.org/directory')
        // Omitted rather than sent empty: Let's Encrypt accepts a registration
        // without one, and an empty string is not an address.
        ->and($spec['acme'])->not->toHaveKey('email');
});

it('carries the operator address when there is one to carry', function (): void {
    test()->compilingFor(new PlatformTarget(
        certificates: Certificates::acme(email: 'ops@example.test'),
    ));

    expect(issuerSpec()['acme']['email'])->toBe('ops@example.test');
});

it('signs locally when nothing outside can reach the cluster', function (): void {
    test()->compilingFor(new PlatformTarget(certificates: Certificates::selfSigned()));

    $spec = issuerSpec();

    // An empty mapping, not an empty list. cert-manager's schema wants an
    // object here, and `selfSigned: []` serialises to a YAML sequence, which
    // the API server rejects — so the distinction is load-bearing.
    expect($spec)->toHaveKey('selfSigned')
        ->and($spec)->not->toHaveKey('acme')
        ->and(json_encode($spec, JSON_THROW_ON_ERROR))->toContain('"selfSigned":{}');
});

it('issues from an authority the operator already has', function (): void {
    test()->compilingFor(new PlatformTarget(
        certificates: Certificates::certificateAuthority('local-dev-ca'),
    ));

    expect(issuerSpec())->toBe(['ca' => ['secretName' => 'local-dev-ca']]);
});

it('says plainly which sources need the cluster to be reachable', function (): void {
    expect(Certificates::acme()->needsInboundReachability())->toBeTrue()
        ->and(Certificates::selfSigned()->needsInboundReachability())->toBeFalse()
        ->and(Certificates::certificateAuthority('ca')->needsInboundReachability())->toBeFalse();
});

it('refuses a source that cannot issue anything', function (): void {
    expect(fn () => Certificates::acme(server: ''))->toThrow(LogicException::class)
        ->and(fn () => Certificates::certificateAuthority(''))->toThrow(LogicException::class);
});

it('compiles the same Certificate objects whoever signs them', function (): void {
    $acme = test()->compileGateway(SpecFactory::gateway(['shop.example.test']));

    test()->compilingFor(new PlatformTarget(certificates: Certificates::selfSigned()));
    $local = test()->compileGateway(SpecFactory::gateway(['shop.example.test']));

    $certificatesOf = fn ($set): array => array_values(array_map(
        fn ($m): array => $m->body,
        array_filter($set->manifests, fn ($m): bool => $m->kind === 'Certificate'),
    ));

    // The whole point of putting this in the target: a local cluster serves the
    // same hostnames out of the same Secrets, so what is tested locally is what
    // is deployed. Only the signer changes.
    expect($certificatesOf($local))->toBe($certificatesOf($acme))
        ->and($certificatesOf($acme))->not->toBe([]);
});
