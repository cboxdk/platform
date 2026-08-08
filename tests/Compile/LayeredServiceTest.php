<?php

declare(strict_types=1);

use Cbox\Platform\Service\ServiceSpec;

function layeredSpec(array $over = []): ServiceSpec
{
    return new ServiceSpec(...array_merge([
        'serviceId' => '01J0000000000000000000SVC1',
        'organizationId' => '01J0000000000000000000ORG1',
        'namespace' => 'cx-prod-abc',
        'name' => 'web',
        'image' => 'zot.cortex-builds.svc:5000/builds/org/web:abc123',
        'baseImage' => 'zot.cortex-builds.svc:5000/cortex/php:8.4-fpm',
        'port' => 8080,
        'replicas' => 1,
    ], $over));
}

function deploymentOf(ServiceSpec $spec): array
{
    return collect(test()->compileService($spec)->manifests)
        ->firstWhere('kind', 'Deployment')->body;
}

it('runs the base image and mounts the application onto it', function (): void {
    // The two swap roles for a layered build. The CONTAINER is the Cortex base
    // image — the runtime, and cbox-init — and the customer's application
    // arrives as an OCI image volume holding nothing else.
    //
    // That is what makes a CVE in PHP or the base OS a tag move rather than a
    // fleet-wide rebuild that can only go as fast as the slowest customer's
    // build.
    $pod = deploymentOf(layeredSpec())['spec']['template']['spec'];
    $container = $pod['containers'][0];

    expect($container['image'])->toBe('zot.cortex-builds.svc:5000/cortex/php:8.4-fpm');

    $volume = collect($pod['volumes'])->firstWhere('name', 'cbox-app');

    expect($volume['image']['reference'])->toBe('zot.cortex-builds.svc:5000/builds/org/web:abc123');

    $mount = collect($container['volumeMounts'])->firstWhere('name', 'cbox-app');

    expect($mount['mountPath'])->toBe('/var/www/html')
        // readOnly is not a precaution, it is what an image volume IS. A
        // process writing to its own code directory loses those writes on
        // reschedule, and hiding that until a pod moves is worse than refusing.
        ->and($mount['readOnly'])->toBeTrue();
});

it('leaves a Dockerfile or prebuilt image exactly as it was', function (): void {
    // No base image means the customer's image is the whole story. Mounting
    // anything onto it would change the behaviour of every service that has
    // ever been deployed from a Dockerfile.
    $pod = deploymentOf(layeredSpec(['baseImage' => '']))['spec']['template']['spec'];

    expect($pod['containers'][0]['image'])->toBe('zot.cortex-builds.svc:5000/builds/org/web:abc123')
        ->and(collect($pod['volumes'] ?? [])->firstWhere('name', 'cbox-app'))->toBeNull();
});

it('mounts the application before anything that goes inside it', function (): void {
    // A Laravel storage/ directory is a persistent volume mounted INSIDE the
    // application path. Kubernetes applies mounts in order, so the app has to
    // come first for the later one to win — otherwise storage/ is whatever the
    // image shipped, read-only, and every write fails at runtime.
    $pod = deploymentOf(layeredSpec())['spec']['template']['spec'];
    $names = collect($pod['containers'][0]['volumeMounts'])->pluck('name')->all();

    expect($names[0])->toBe('cbox-app');
});
