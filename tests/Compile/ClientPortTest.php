<?php

declare(strict_types=1);

use Cbox\Platform\Capability\ClientPort;
use Cbox\Platform\Capability\PlatformTarget;
use Cbox\Platform\Service\ServiceSpec;

/*
 * Which port the client reached, for the one substrate where it is not 443.
 */

function routedSpec(): ServiceSpec
{
    return new ServiceSpec(
        serviceId: '01J0000000000000000000SVC1',
        organizationId: 'local',
        namespace: 'cbox-demo',
        name: 'demo',
        image: 'ghcr.io/cboxdk/php:8.4-fpm',
        port: 8080,
        replicas: 1,
        domains: ['demo.cbox.test'],
    );
}

function routeOf(ServiceSpec $spec): array
{
    return collect(test()->compileService($spec)->manifests)
        ->firstWhere('kind', 'HTTPRoute')->body['spec']['rules'][0];
}

it('announces a port that is not the default, on the request itself', function (): void {
    // Gateway API strips the port from `:authority`, and the pod's own port is
    // 80 — so an application on a machine whose gateway took 18443 builds every
    // URL without it, and a login redirect lands on whatever holds 443.
    test()->compilingFor(new PlatformTarget(clientPort: ClientPort::nonStandard(18443)));

    expect(routeOf(routedSpec())['filters'])->toBe([[
        'type' => 'RequestHeaderModifier',
        'requestHeaderModifier' => [
            'set' => [['name' => 'X-Forwarded-Port', 'value' => '18443']],
        ],
    ]]);
});

it('compiles nothing at all on 443', function (): void {
    // Every hosted cluster. An empty `filters` key would be a difference in
    // every golden manifest for a capability nobody there uses.
    test()->compilingFor(new PlatformTarget);

    expect(routeOf(routedSpec()))->not->toHaveKey('filters');
});

it('refuses a number that is not a port', function (): void {
    expect(fn () => ClientPort::nonStandard(0))->toThrow(LogicException::class, 'not a port');
    expect(fn () => ClientPort::nonStandard(70000))->toThrow(LogicException::class, 'not a port');
});
