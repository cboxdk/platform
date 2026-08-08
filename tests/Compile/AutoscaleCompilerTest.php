<?php

declare(strict_types=1);

use Cbox\Platform\Service\ServiceSpec;

/**
 * A service scaled to zero and back to a fixed replica count. Nothing
 * responded to load.
 */
beforeEach(function (): void {
    // No snapshotting runtime on this cell, so scale-to-zero takes the KEDA
    // HTTP path rather than the CRIU one. Bound here rather than borrowed from
    // another test file: a helper defined in one file only resolves when that
    // file happens to be loaded too.
    test()->compilingWithSnapshotRuntime(null);
});

function autoscaleSpec(
    ?int $min = 2,
    ?int $max = 8,
    ?int $cpu = 70,
    bool $scaleToZero = false,
    bool $suspended = false,
): ServiceSpec {
    return new ServiceSpec(
        serviceId: '01J0000000000000000000SVC1',
        organizationId: '01J0000000000000000000ORG1',
        namespace: 'cx-production-svc9k2',
        name: 'web',
        image: 'ghcr.io/acme/web:1',
        port: 3000,
        replicas: 3,
        domains: ['app.acme.example'],
        autoscaleMin: $min,
        autoscaleMax: $max,
        autoscaleCpuPercent: $cpu,
        scaleToZero: $scaleToZero,
        suspended: $suspended,
    );
}

function scaledObject(ServiceSpec $spec): ?object
{
    return collect(test()->compileService($spec)->manifests)
        ->first(fn ($m): bool => $m->kind === 'ScaledObject');
}

it('compiles a CPU trigger against the request, not the node', function (): void {
    // Utilization is a percentage of the pod's REQUEST, so 70% means the same
    // thing on a two-core worker and a sixteen-core one. A value measured
    // against the node would mean something different on every machine type.
    $object = scaledObject(autoscaleSpec());

    expect($object)->not->toBeNull()
        ->and($object?->body['spec']['triggers'][0]['type'])->toBe('cpu')
        ->and($object?->body['spec']['triggers'][0]['metricType'])->toBe('Utilization')
        ->and($object?->body['spec']['triggers'][0]['metadata']['value'])->toBe('70')
        ->and($object?->body['spec']['maxReplicaCount'])->toBe(8);
});

it('never scales to zero on CPU', function (): void {
    // It cannot work: a pod using no CPU is the signal to remove it, and once
    // it is gone there is no CPU to read and nothing that would bring it back.
    // That is what the HTTP add-on tier is for.
    expect(scaledObject(autoscaleSpec(min: 0))?->body['spec']['minReplicaCount'])->toBe(1);
});

it('compiles nothing when the policy is incomplete', function (): void {
    // A max with no target has nothing to scale on; a target with no max has
    // nowhere to stop.
    expect(scaledObject(autoscaleSpec(cpu: null)))->toBeNull()
        ->and(scaledObject(autoscaleSpec(max: null)))->toBeNull()
        ->and(scaledObject(autoscaleSpec(min: null)))->toBeNull();
});

it('will not put two autoscalers on one Deployment', function (): void {
    // The scale-to-zero tier already owns the replica count from zero upward.
    // Two scalers pointed at one Deployment write spec.replicas at each other
    // every few seconds and the pod count oscillates for reasons no single
    // object explains.
    $set = test()->compileService(autoscaleSpec(scaleToZero: true));

    $kinds = array_map(static fn ($m): string => $m->kind, $set->manifests);

    expect($kinds)->toContain('HTTPScaledObject')
        ->and($kinds)->not->toContain('ScaledObject');
});

it('does not autoscale a suspended service', function (): void {
    // Suspend means stopped. A scaler that started a pod back up would make
    // the button a suggestion.
    expect(scaledObject(autoscaleSpec(suspended: true)))->toBeNull();
});

it('hands the replica count to the scaler rather than fighting it', function (): void {
    // Seeding the customer's fixed number would have the Deployment and the
    // ScaledObject write `replicas` at each other on every reconcile.
    $deployment = collect(test()->compileService(autoscaleSpec(min: 2))->manifests)
        ->firstWhere('kind', 'Deployment');

    expect($deployment?->body['spec']['replicas'])->toBe(2);
});

it('leaves a service without a policy exactly as it was', function (): void {
    $set = test()->compileService(autoscaleSpec(min: null, max: null, cpu: null));

    $kinds = array_map(static fn ($m): string => $m->kind, $set->manifests);
    $deployment = collect($set->manifests)->firstWhere('kind', 'Deployment');

    expect($kinds)->not->toContain('ScaledObject')
        ->and($deployment?->body['spec']['replicas'])->toBe(3);
});
