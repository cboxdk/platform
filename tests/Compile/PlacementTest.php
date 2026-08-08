<?php

declare(strict_types=1);

use Cbox\Platform\Service\ProcessSpec;
use Cbox\Platform\Service\ServiceSpec;

/**
 * There was no placement of any kind: three replicas could all land on one
 * node, and one node dying took the whole service with it. An availability
 * hole in a single datacentre, before any multi-region question arises.
 */
function placementSpec(array $processes = []): ServiceSpec
{
    return new ServiceSpec(
        serviceId: '01J0000000000000000000SVC1',
        organizationId: '01J0000000000000000000ORG1',
        namespace: 'cx-production-db9k2',
        name: 'web',
        image: 'nginx:1.27-alpine',
        port: 80,
        replicas: 3,
        processes: $processes,
    );
}

it('spreads a service across nodes', function (): void {
    $pod = collect(test()->compileService(placementSpec())->manifests)
        ->firstWhere('kind', 'Deployment')->body['spec']['template']['spec'];

    $constraint = $pod['topologySpreadConstraints'][0];

    expect($constraint['topologyKey'])->toBe('kubernetes.io/hostname')
        ->and($constraint['maxSkew'])->toBe(1);
});

it('prefers to spread rather than refusing to schedule', function (): void {
    $pod = collect(test()->compileService(placementSpec())->manifests)
        ->firstWhere('kind', 'Deployment')->body['spec']['template']['spec'];

    // A hard constraint on a cluster with fewer nodes than replicas leaves
    // pods Pending forever. A customer who asks for three replicas on a
    // two-node pool should get three running pods unevenly spread, not two and
    // a permanent failure.
    expect($pod['topologySpreadConstraints'][0]['whenUnsatisfiable'])->toBe('ScheduleAnyway');
});

it('selects something that actually exists', function (): void {
    $deployment = collect(test()->compileService(placementSpec())->manifests)
        ->firstWhere('kind', 'Deployment')->body;

    $labels = $deployment['spec']['template']['metadata']['labels'];
    $selector = $deployment['spec']['template']['spec']['topologySpreadConstraints'][0]['labelSelector']['matchLabels'];

    // The trap this test exists for: a constraint whose selector matches no
    // pod is a silent no-op — the pods schedule wherever, and the object looks
    // configured. Every key it selects on must be on the pods it is spreading.
    foreach ($selector as $key => $value) {
        expect($labels)->toHaveKey($key)->and($labels[$key])->toBe($value);
    }
});

it('spreads each process on its own, not against the web pods', function (): void {
    $set = test()->compileService(placementSpec([
        new ProcessSpec('worker', ['php', 'artisan', 'queue:work'], 3),
    ]));

    $worker = collect($set->manifests)->firstWhere('name', 'web-worker')->body;
    $selector = $worker['spec']['template']['spec']['topologySpreadConstraints'][0]['labelSelector']['matchLabels'];

    // Spreading workers against web pods would let a node full of web replicas
    // push workers somewhere they are not needed, and vice versa — two
    // unrelated workloads fighting over one skew budget.
    expect($selector['cortex.io/process'])->toBe('worker')
        ->and($worker['spec']['template']['metadata']['labels']['cortex.io/process'])->toBe('worker');
});
