<?php

declare(strict_types=1);

use Cbox\Platform\Capability\GatewayOwnership;
use Cbox\Platform\Capability\PlatformTarget;
use Cbox\Platform\Route\EnvironmentGatewaySpec;
use Cbox\Platform\Service\ProcessSpec;
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

/**
 * A service's WORKERS are separate Deployments carrying the same service label,
 * so anything selecting on that label alone reaches them too.
 *
 * Measured on a local cluster with one web process and one worker: the web
 * Deployment adopted the worker's pod and reported two replicas for a service
 * asking for one, and the gateway load-balanced HTTP across a web process and a
 * queue worker — which answers nothing, so roughly half of all requests were
 * 503s that came and went.
 */
function serviceWithAWorker(): ServiceSpec
{
    return new ServiceSpec(
        serviceId: '01J0000000000000000000SVC1',
        organizationId: '01J0000000000000000000ORG1',
        namespace: 'cx-production-db9k2',
        name: 'web',
        image: 'ghcr.io/acme/web:1.4.2',
        port: 3000,
        replicas: 2,
        // Two, so a PodDisruptionBudget is emitted at all — a single replica
        // has no disruption to budget for.
        processes: [new ProcessSpec(
            name: 'queue',
            command: ['php', 'artisan', 'queue:work'],
            replicas: 1,
        )],
        domains: ['app.acme.example'],
    );
}

it('does not route a service s traffic to its own workers', function (): void {
    $set = test()->compileService(serviceWithAWorker());

    $service = $set->find('Service/web');
    expect($service)->not->toBeNull();

    // The label a worker pod also carries is not enough on its own.
    expect($service->body['spec']['selector'])
        ->toHaveKey('platform.cbox.dk/process')
        ->and($service->body['spec']['selector']['platform.cbox.dk/process'])->toBe('web');
});

it('does not let the web deployment adopt its own workers', function (): void {
    $set = test()->compileService(serviceWithAWorker());

    $web = $set->find('Deployment/web');
    $worker = $set->find('Deployment/web-queue');

    expect($web)->not->toBeNull()
        ->and($worker)->not->toBeNull();

    $webSelector = $web->body['spec']['selector']['matchLabels'];
    $workerLabels = $worker->body['spec']['template']['metadata']['labels'];

    // Two controllers managing one pod is a replica count they fight over, and
    // one of them deleting what the other just made.
    $adopted = true;

    foreach ($webSelector as $key => $value) {
        if (($workerLabels[$key] ?? null) !== $value) {
            $adopted = false;

            break;
        }
    }

    expect($adopted)->toBeFalse();
});

it('protects exactly the pods the web deployment owns', function (): void {
    $set = test()->compileService(serviceWithAWorker());

    // A budget selecting pods the Deployment does not own protects the wrong
    // set; one selecting fewer protects nothing while looking like it does.
    expect($set->find('PodDisruptionBudget/web')->body['spec']['selector']['matchLabels'])
        ->toBe($set->find('Deployment/web')->body['spec']['selector']['matchLabels']);
});
