<?php

declare(strict_types=1);

use Cbox\Platform\Capability\ApplicationSource;
use Cbox\Platform\Capability\PlatformTarget;
use Cbox\Platform\Service\ServiceSpec;
use Cbox\Platform\Service\SourceMount;

/*
 * Where a service's code comes from.
 *
 * An image everywhere it should be. On a development machine, the developer's
 * own directory — because somebody editing a file wants the next request to run
 * it, and a build and a push between the two is the thing they are getting away
 * from.
 */

function sourceSpec(array $over = []): ServiceSpec
{
    return new ServiceSpec(...array_merge([
        'serviceId' => '01J0000000000000000000SVC1',
        'organizationId' => 'local',
        'namespace' => 'cbox-demo',
        'name' => 'demo',
        'image' => 'ghcr.io/cboxdk/php:8.4-fpm',
        'port' => 8080,
        'replicas' => 1,
        'sourcePath' => '/Users/dev/Projects/demo',
    ], $over));
}

function podOf(ServiceSpec $spec): array
{
    return collect(test()->compileService($spec)->manifests)
        ->firstWhere('kind', 'Deployment')->body['spec']['template']['spec'];
}

it('mounts the developer directory where the image would have served from', function (): void {
    test()->compilingFor(new PlatformTarget(
        applicationSource: ApplicationSource::hostPath('/host'),
    ));

    $pod = podOf(sourceSpec());

    $volume = collect($pod['volumes'])->firstWhere('name', 'cbox-app');
    $mount = collect($pod['containers'][0]['volumeMounts'])->firstWhere('name', 'cbox-app');

    expect($volume['hostPath']['path'])->toBe('/host/Users/dev/Projects/demo')
        // Refuses to start rather than creating an empty directory and serving
        // nothing, which is what DirectoryOrCreate does with a path typed one
        // character wrong.
        ->and($volume['hostPath']['type'])->toBe('Directory')
        ->and($mount['mountPath'])->toBe('/var/www/html')
        // WRITABLE, unlike an image volume: it is the developer's working copy,
        // and a framework that cannot write a cache file into its own tree fails
        // in ways that look like the framework's fault.
        ->and($mount)->not->toHaveKey('readOnly');
});

it('refuses a service that asks a platform serving images', function (): void {
    // A hostPath mount reads and writes the NODE, and the request for one
    // arrives inside customer intent. Compiling it away would be worse than
    // refusing: somebody would get a deployment running the image's own code
    // while believing it runs their working copy, and every edit would appear
    // to do nothing.
    test()->compilingFor(new PlatformTarget);

    expect(fn () => test()->compileService(sourceSpec()))
        ->toThrow(LogicException::class, 'serves applications from images');
});

it('refuses a path that is not absolute', function (): void {
    test()->compilingFor(new PlatformTarget(
        applicationSource: ApplicationSource::hostPath('/host'),
    ));

    expect(fn () => test()->compileService(sourceSpec(['sourcePath' => 'Projects/demo'])))
        ->toThrow(LogicException::class, 'has to be an absolute path');
});

it('takes the source over an image volume when a service has both', function (): void {
    // Locally the base image IS the container and the built artifact is not
    // wanted at all. Mounting both would put the image's copy of the
    // application over the developer's, at the same path, and which one won
    // would depend on the order of a list.
    test()->compilingFor(new PlatformTarget(
        applicationSource: ApplicationSource::hostPath('/host'),
    ));

    $pod = podOf(sourceSpec([
        'image' => 'ghcr.io/acme/demo:abc123',
        'baseImage' => 'ghcr.io/cboxdk/php:8.4-fpm',
    ]));

    $volumes = collect($pod['volumes'])->where('name', 'cbox-app');

    expect($volumes)->toHaveCount(1)
        ->and($volumes->first())->not->toHaveKey('image');
});

it('leaves every other service exactly as it was', function (): void {
    // The capability is a difference a target declares, not a change to the
    // shape everything else compiles to.
    test()->compilingFor(new PlatformTarget(
        applicationSource: ApplicationSource::hostPath('/host'),
    ));

    $pod = podOf(sourceSpec(['sourcePath' => '']));

    expect(collect($pod['volumes'] ?? [])->firstWhere('name', 'cbox-app'))->toBeNull();
});

it('overlays an extra mount on top of the application, in that order', function (): void {
    // The case a single source path cannot express: a package installed into a
    // throwaway application by composer, then OVERLAID by the developer's real
    // directory so an edit is live. Inside the application's own path, because
    // that is what keeps it inside the runtime's open_basedir — mounting it
    // beside would need those restrictions widened, a worse trade than a mount.
    test()->compilingFor(new PlatformTarget(
        applicationSource: ApplicationSource::hostPath('/host'),
    ));

    $pod = podOf(sourceSpec([
        'mounts' => [new SourceMount('/Users/dev/Projects/pkg', '/var/www/html/vendor/acme/pkg')],
    ]));

    $names = array_column($pod['volumes'], 'name');
    $paths = array_column($pod['containers'][0]['volumeMounts'], 'mountPath');

    expect($names[0])->toBe('cbox-app')
        // AFTER the application: a later mount shadows an earlier one, so the
        // order is the behaviour.
        ->and($paths)->toBe(['/var/www/html', '/var/www/html/vendor/acme/pkg'])
        ->and(collect($pod['volumes'])->last()['hostPath']['path'])
        ->toBe('/host/Users/dev/Projects/pkg');
});

it('refuses an extra mount on a platform that serves images', function (): void {
    // A hostPath reads and writes the node, and the request arrives inside
    // customer intent — the same gate as the source path itself.
    test()->compilingFor(new PlatformTarget);

    expect(fn () => test()->compileService(sourceSpec([
        'sourcePath' => '',
        'mounts' => [new SourceMount('/Users/dev/x', '/var/www/html/x')],
    ])))->toThrow(LogicException::class, 'serves applications from images');
});

it('refuses a mount path that is not absolute', function (): void {
    expect(fn () => new SourceMount('relative', '/var/www/html/x'))
        ->toThrow(LogicException::class, 'has to be absolute');
});
