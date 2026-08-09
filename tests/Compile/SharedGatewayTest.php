<?php

declare(strict_types=1);

use Cbox\Platform\Capability\GatewayOwnership;
use Cbox\Platform\Capability\PlatformTarget;
use Cbox\Platform\Route\EnvironmentGatewaySpec;
use Cbox\Platform\Service\ServiceSpec;

/**
 * A hosted cluster gives each environment its own Gateway. A shared development
 * cluster has one for the whole machine, and not by preference: its port
 * mappings are fixed when the cluster is built, so the node ports its gateway
 * publishes must be pinned — and two Services cannot hold the same node port.
 *
 * The shape came from the substrate. This is the capability that lets a consumer
 * say so, instead of the compiler guessing or the consumer patching around it.
 */
function sharedGatewayService(): ServiceSpec
{
    return new ServiceSpec(
        serviceId: '01J0000000000000000000SVC1',
        organizationId: '01J0000000000000000000ORG1',
        namespace: 'cx-production-db9k2',
        name: 'web',
        image: 'ghcr.io/acme/web:1.4.2',
        port: 3000,
        replicas: 1,
        domains: ['app.acme.example'],
    );
}

function sharedTarget(): PlatformTarget
{
    return new PlatformTarget(
        gatewayOwnership: GatewayOwnership::shared(namespace: 'cbox-system', name: 'cbox'),
    );
}

it('compiles no gateway of its own when one is shared', function (): void {
    test()->compilingFor(sharedTarget());

    $set = test()->compileGateway(new EnvironmentGatewaySpec(
        environmentId: '01J0000000000000000000ENV1',
        organizationId: '01J0000000000000000000ORG1',
        namespace: 'cx-production-db9k2',
        domains: ['app.acme.example'],
    ));

    // Its listeners, the certificates they terminate and the policy that carries
    // client addresses all belong to whoever installed it. An environment
    // compiling its own copies would either collide on name or — worse — apply
    // cleanly and be ignored.
    expect($set->manifests)->toBe([]);
});

it('still compiles a gateway when the environment owns one', function (): void {
    // The default, and the thing every existing consumer relies on.
    $set = test()->compileGateway(new EnvironmentGatewaySpec(
        environmentId: '01J0000000000000000000ENV1',
        organizationId: '01J0000000000000000000ORG1',
        namespace: 'cx-production-db9k2',
        domains: ['app.acme.example'],
    ));

    expect(collect($set->manifests)->pluck('kind')->all())->toContain('Gateway');
});

it('points a route at the shared gateway, across the namespace boundary', function (): void {
    test()->compilingFor(sharedTarget());

    $route = collect(test()->compileService(sharedGatewayService())->manifests)
        ->firstWhere('kind', 'HTTPRoute');

    expect($route)->not->toBeNull();

    $parent = $route->body['spec']['parentRefs'][0];

    // BOTH halves, and the namespace is the half that is easy to leave out: a
    // parentRef without one means "this namespace", which is right when the
    // environment owns its gateway and silently wrong when it does not. The
    // route is then Accepted=False for a reason that reads like a routing bug.
    expect($parent['name'])->toBe('cbox')
        ->and($parent['namespace'])->toBe('cbox-system');
});

it('points a route at its own gateway when nothing is shared', function (): void {
    $route = collect(test()->compileService(sharedGatewayService())->manifests)
        ->firstWhere('kind', 'HTTPRoute');

    $parent = $route->body['spec']['parentRefs'][0];

    expect($parent['name'])->toBe('cbox-gateway')
        ->and($parent['namespace'])->toBe('cx-production-db9k2');
});

it('refuses a shared gateway that is missing half its address', function (): void {
    // A route naming a Gateway that does not exist is Accepted=False with a
    // reason nobody reads; naming the right Gateway in the wrong namespace is
    // the same thing with a more confusing message. Neither half is guessable.
    expect(fn () => GatewayOwnership::shared(namespace: '', name: 'cbox'))
        ->toThrow(LogicException::class);

    expect(fn () => GatewayOwnership::shared(namespace: 'cbox-system', name: '  '))
        ->toThrow(LogicException::class);
});
