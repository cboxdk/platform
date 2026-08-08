<?php

declare(strict_types=1);

use Cbox\Platform\Contracts\Compiler;
use Cbox\Platform\Runtime\CortexSnapshotRuntime;
use Cbox\Platform\Service\ServiceSpec;

function cortexRuntimeSpec(bool $scaleToZero = true, bool $suspended = false): ServiceSpec
{
    test()->compilingWithSnapshotRuntime(new CortexSnapshotRuntime);

    return new ServiceSpec(
        serviceId: '01J0000000000000000000SVC7',
        organizationId: '01J0000000000000000000ORG1',
        namespace: 'cx-production-svc9k2',
        name: 'web',
        image: 'ghcr.io/acme/web:1.4.2',
        port: 3000,
        replicas: 2,
        env: ['APP_ENV' => 'production'],
        domains: ['app.acme.example'],
        scaleToZero: $scaleToZero,
        suspended: $suspended,
        idleTimeoutSeconds: 300,
    );
}

it('offers the warm tier without a RuntimeClass', function (): void {
    $runtime = new CortexSnapshotRuntime;

    // The pair that matters: available, yet nothing special about the pod. The
    // container is scheduled by the node's ordinary runtime and stays genuinely
    // running while asleep, because only cbox-init's children are checkpointed.
    expect($runtime->isAvailable())->toBeTrue()
        ->and($runtime->runtimeClassName())->toBeNull();
});

it('annotates under our own namespace, not a vendor prefix', function (): void {
    $annotations = (new CortexSnapshotRuntime)->annotations('web', 3000, 300);

    expect($annotations)->toBe([
        'snapshot.cboxcortex.com/container-names' => 'web',
        'snapshot.cboxcortex.com/ports-map' => 'web=3000',
        'snapshot.cboxcortex.com/idle-timeout' => '300s',
    ]);

    foreach (array_keys($annotations) as $key) {
        expect($key)->not->toContain('zeropod');
    }
});

it('forces the two settings CRIU needs from the workload', function (): void {
    // Both were confirmed on a node rather than assumed: MPTCP made the dump
    // fail outright with "Unsupported proto 262", and an OPcache JIT arena
    // leaves an RWX region the dump parasite cannot be injected through.
    expect((new CortexSnapshotRuntime)->environment())->toBe([
        'GODEBUG' => 'multipathtcp=0',
        'PHP_OPCACHE_JIT' => 'off',
        'CBOX_SNAPSHOT_SOCKET' => '/run/cortex/snapshot.sock',
    ]);
});

it('adds nothing at all when the cell does not offer it', function (): void {
    $runtime = new CortexSnapshotRuntime(available: false);

    expect($runtime->isAvailable())->toBeFalse()
        ->and($runtime->runtimeClassName())->toBeNull()
        ->and($runtime->annotations('web', 3000, 300))->toBe([]);
});

it('compiles a pod that is annotated but carries no runtimeClassName', function (): void {
    // Build the spec first: it binds the cell's runtime, and the compiler must
    // be resolved only afterwards.
    $spec = cortexRuntimeSpec();
    $set = test()->compileService($spec);
    $deployment = collect($set->manifests)->firstWhere('kind', 'Deployment');

    $pod = $deployment->body['spec']['template'];

    expect($pod['spec'])->not->toHaveKey('runtimeClassName')
        ->and($pod['metadata']['annotations'])
        ->toHaveKey('snapshot.cboxcortex.com/ports-map', 'web=3000');
});

it('keeps the pod scheduled, because a checkpoint needs something to checkpoint', function (): void {
    // Build the spec first: it binds the cell's runtime, and the compiler must
    // be resolved only afterwards.
    $spec = cortexRuntimeSpec();
    $set = test()->compileService($spec);
    $deployment = collect($set->manifests)->firstWhere('kind', 'Deployment');

    // The warm tier's "zero" is a checkpointed process, not a missing pod, so
    // the customer's replica count stands — unlike the KEDA tier, which scales
    // the Deployment to zero and would delete the very thing we snapshot.
    expect($deployment->body['spec']['replicas'])->toBe(2);
});

it('puts the workload somewhere other than the port clients reach', function (): void {
    // cbox-init holds the service port so a connection's handshake completes
    // while the workload is asleep. Both ends being ours is what lets this be a
    // convention rather than a discovery.
    expect(CortexSnapshotRuntime::upstream(3000))->toBe('127.0.0.1:13000')
        ->and(CortexSnapshotRuntime::upstream(80))->toBe('127.0.0.1:10080');
});
