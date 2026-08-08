<?php

declare(strict_types=1);

use Cbox\Platform\Service\ProcessSpec;
use Cbox\Platform\Service\ServiceSpec;
use Cbox\Platform\Service\VolumeSpec;

/**
 * A service could not have a disk at all, so an application with an uploads
 * directory could not run — everything it wrote vanished with the pod.
 *
 * Everything below is shaped by one fact: block storage is ReadWriteOnce. The
 * Hetzner CSI driver offers nothing else and every volume in the live tenant
 * is RWO, so exactly one node holds a volume at a time.
 */
function volumeSpec(
    array $volumes = [],
    int $replicas = 1,
    array $processes = [],
): ServiceSpec {
    return new ServiceSpec(
        serviceId: '01J0000000000000000000SVC1',
        organizationId: '01J0000000000000000000ORG1',
        namespace: 'cx-production-svc9k2',
        name: 'web',
        image: 'ghcr.io/acme/web:1',
        port: 3000,
        replicas: $replicas,
        volumes: $volumes,
        processes: $processes,
    );
}

it('compiles a claim and mounts it', function (): void {
    $set = test()->compileService(volumeSpec([
        new VolumeSpec('uploads', '/var/www/storage', '20Gi'),
    ]));

    $claim = collect($set->manifests)->firstWhere('kind', 'PersistentVolumeClaim');

    expect($claim)->not->toBeNull()
        ->and($claim?->name)->toBe('web-uploads')
        ->and($claim?->body['spec']['resources']['requests']['storage'])->toBe('20Gi')
        // Not a default that could be relaxed: it is the property every rule
        // below follows from.
        ->and($claim?->body['spec']['accessModes'])->toBe(['ReadWriteOnce']);

    $deployment = collect($set->manifests)->firstWhere('kind', 'Deployment');
    $pod = $deployment?->body['spec']['template']['spec'];

    expect($pod['volumes'][0]['persistentVolumeClaim']['claimName'])->toBe('web-uploads')
        ->and($pod['containers'][0]['volumeMounts'][0]['mountPath'])->toBe('/var/www/storage');
});

it('compiles the claim before the Deployment that mounts it', function (): void {
    // A pod scheduled against a claim that does not exist stays Pending with
    // an event nobody is reading.
    $set = test()->compileService(volumeSpec([
        new VolumeSpec('uploads', '/data', '10Gi'),
    ]));

    $kinds = array_map(static fn ($m): string => $m->kind, $set->manifests);

    expect(array_search('PersistentVolumeClaim', $kinds, true))
        ->toBeLessThan(array_search('Deployment', $kinds, true));
});

it('will not roll a Deployment that holds a volume', function (): void {
    // The one that bites hardest. A RollingUpdate starts the replacement pod
    // before stopping the old one, and the old one still holds the RWO volume
    // — the new pod cannot attach, the old is never told to stop because the
    // new never becomes ready, and the deploy hangs until somebody deletes a
    // pod by hand.
    $withVolume = test()->compileService(volumeSpec([
        new VolumeSpec('uploads', '/data', '10Gi'),
    ]));
    $without = test()->compileService(volumeSpec());

    $strategy = fn ($set) => collect($set->manifests)
        ->firstWhere('kind', 'Deployment')?->body['spec']['strategy']['type'] ?? null;

    expect($strategy($withVolume))->toBe('Recreate')
        ->and($strategy($without))->toBeNull();
});

it('refuses a service that wants a volume and several replicas', function (): void {
    // The second replica would sit in ContainerCreating forever with a
    // Multi-Attach error nobody outside Kubernetes can read. A sentence at
    // compile time beats a permanently broken deploy.
    expect(fn () => test()->compileService(volumeSpec(
        volumes: [new VolumeSpec('uploads', '/data', '10Gi')],
        replicas: 3,
    )))->toThrow(LogicException::class, 'one node at a time');
});

it('refuses a process that mounts a volume and wants several replicas', function (): void {
    expect(fn () => test()->compileService(volumeSpec(
        volumes: [new VolumeSpec('uploads', '/data', '10Gi', ['worker'])],
        processes: [new ProcessSpec('worker', ['queue:work'], 4)],
    )))->toThrow(LogicException::class);
});

it('lets a process scale when the volume is not its', function (): void {
    // Narrowing a volume to one process is the whole reason processes can be
    // named: a worker that does not touch the uploads directory should still
    // be able to run four of itself.
    $set = test()->compileService(volumeSpec(
        volumes: [new VolumeSpec('uploads', '/data', '10Gi', ['web'])],
        processes: [new ProcessSpec('worker', ['queue:work'], 4)],
    ));

    $worker = collect($set->manifests)
        ->first(fn ($m): bool => $m->kind === 'Deployment' && str_contains($m->name, 'worker'));

    expect($worker?->body['spec']['replicas'])->toBe(4)
        ->and($worker?->body['spec']['template']['spec']['volumes'] ?? null)->toBeNull()
        ->and($worker?->body['spec']['strategy'] ?? null)->toBeNull();
});

it('mounts a volume into every process when none is named', function (): void {
    // What somebody means when they add a volume without thinking about it.
    $set = test()->compileService(volumeSpec(
        volumes: [new VolumeSpec('shared', '/shared', '10Gi')],
        processes: [new ProcessSpec('worker', ['queue:work'], 1)],
    ));

    $worker = collect($set->manifests)
        ->first(fn ($m): bool => $m->kind === 'Deployment' && str_contains($m->name, 'worker'));

    expect($worker?->body['spec']['template']['spec']['volumes'][0]['name'])->toBe('shared')
        ->and($worker?->body['spec']['template']['spec']['containers'][0]['volumeMounts'][0]['mountPath'])
        ->toBe('/shared');
});

it('compiles nothing extra for a service with no volumes', function (): void {
    $set = test()->compileService(volumeSpec());

    $kinds = array_map(static fn ($m): string => $m->kind, $set->manifests);

    expect($kinds)->not->toContain('PersistentVolumeClaim');

    $pod = collect($set->manifests)->firstWhere('kind', 'Deployment')?->body['spec']['template']['spec'];

    expect($pod['volumes'] ?? null)->toBeNull()
        ->and($pod['containers'][0]['volumeMounts'] ?? null)->toBeNull();
});
