<?php

declare(strict_types=1);

use Cbox\Platform\Binding\BindingSpec;
use Cbox\Platform\Binding\ConnectionField;
use Cbox\Platform\Binding\ConnectionSource;
use Cbox\Platform\Compile\ReleaseJobCompiler;
use Cbox\Platform\Service\HealthCheck;
use Cbox\Platform\Service\ProcessSpec;
use Cbox\Platform\Service\SecretReference;
use Cbox\Platform\Service\ServiceSpec;

/**
 * The release command — `php artisan migrate --force` — as a Job an applier runs
 * to completion BEFORE the Deployments roll.
 *
 * Also the production-shaped golden: a web process with two replicas, a queue
 * manager and a scheduler, a database binding, every secret by reference, and
 * probes — the shape a real identity provider deploys in.
 */
function releasedSpec(array $override = []): ServiceSpec
{
    return new ServiceSpec(...[
        'serviceId' => 'cbox-id',
        'organizationId' => 'local',
        'namespace' => 'cbox-cbox-id',
        'name' => 'cbox-id',
        'image' => 'ghcr.io/cboxdk/php-baseimages/php-fpm-nginx:8.5-bookworm-v1',
        'port' => 80,
        'replicas' => 2,
        'env' => ['APP_ENV' => 'production', 'LOG_CHANNEL' => 'stderr'],
        'bindings' => [new BindingSpec(
            databaseName: 'database',
            engine: 'postgres',
            map: [
                ['field' => ConnectionField::Host, 'name' => 'DB_HOST'],
                ['field' => ConnectionField::Password, 'name' => 'DB_PASSWORD'],
            ],
            source: new ConnectionSource('database-app', ['password' => 'password'], ['host' => 'database-rw']),
        )],
        'processes' => [
            new ProcessSpec('queue', ['php', 'artisan', 'queue:autoscale'], 1),
            new ProcessSpec('scheduler', ['php', 'artisan', 'schedule:work'], 1),
        ],
        'secretRefs' => [
            new SecretReference('APP_KEY', 'cbox-id-app', 'APP_KEY'),
            new SecretReference('HEALTH_TOKEN', 'cbox-id-app', 'HEALTH_TOKEN'),
        ],
        'health' => new HealthCheck('/health/ready', '/up', 'HEALTH_TOKEN'),
        'release' => ['php', 'artisan', 'migrate', '--force'],
        ...$override,
    ]);
}

it('compiles nothing to run first when there is no release command', function (): void {
    expect(test()->compileRelease(releasedSpec(['release' => []]))->manifests)->toBe([]);
});

it('runs nothing for a service that is put away', function (): void {
    // A migration nobody is going to serve is a migration nobody is watching.
    expect(test()->compileRelease(releasedSpec(['suspended' => true]))->manifests)->toBe([]);
});

it('runs the release in exactly what the new pods will see', function (): void {
    $spec = releasedSpec();
    $job = test()->compileRelease($spec)->manifests[0];
    $web = test()->compileService($spec)->find('Deployment/cbox-id');

    $container = $job->body['spec']['template']['spec']['containers'][0];
    $served = $web?->body['spec']['template']['spec']['containers'][0] ?? [];

    expect($job->kind)->toBe('Job')
        ->and($container['command'])->toBe(['php', 'artisan', 'migrate', '--force'])
        // The same image, env, references and binding — it IS the web
        // container, with a command on it.
        ->and($container['image'])->toBe($served['image'])
        ->and($container['env'])->toBe($served['env'])
        ->and($container)->not->toHaveKeys(['ports', 'readinessProbe', 'livenessProbe', 'startupProbe'])
        ->and($job->body['spec']['template']['spec']['restartPolicy'])->toBe('Never')
        // A migration that failed halfway is not improved by running again
        // unattended.
        ->and($job->body['spec']['backoffLimit'])->toBe(0);
});

it('names the Job for what it runs', function (): void {
    $first = test()->compileRelease(releasedSpec())->manifests[0];
    $again = test()->compileRelease(releasedSpec())->manifests[0];
    $newImage = test()->compileRelease(releasedSpec(['image' => 'ghcr.io/cboxdk/php-baseimages/php-fpm-nginx:8.5-bookworm-v2']))->manifests[0];
    $newCommand = test()->compileRelease(releasedSpec(['release' => ['php', 'artisan', 'migrate', '--force', '--step']]))->manifests[0];
    $newSecret = test()->compileRelease(releasedSpec(['secretRefs' => [
        new SecretReference('APP_KEY', 'cbox-id-app', 'APP_KEY'),
        new SecretReference('HEALTH_TOKEN', 'cbox-id-app', 'HEALTH_TOKEN'),
        new SecretReference('MAIL_PASSWORD', 'cbox-id-mail', 'MAIL_PASSWORD'),
    ]]))->manifests[0];

    // The same release twice is the same Job — an applier finds it complete
    // instead of migrating twice.
    expect($again->name)->toBe($first->name)
        ->and($first->name)->toMatch('/^cbox-id-release-[0-9a-f]{10}$/')
        ->and($first->body['metadata']['name'])->toBe($first->name);

    // Anything that changes the pod is a new Job. A Job's template is immutable,
    // so a fixed name could only ever run the first release.
    expect([$newImage->name, $newCommand->name, $newSecret->name])->each->not->toBe($first->name);
    expect(array_unique([$newImage->name, $newCommand->name, $newSecret->name]))->toHaveCount(3);
});

it('keeps the name a valid pod label for a long service name', function (): void {
    $name = ReleaseJobCompiler::jobName(str_repeat('a', 50).'-'.str_repeat('b', 12), str_repeat('f', 64));

    expect(strlen($name))->toBeLessThanOrEqual(63)
        ->and($name)->toMatch('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/')
        ->and($name)->toEndWith('-release-ffffffffff');
});

it('matches the production-shaped golden, service and release, byte for byte', function (): void {
    $yaml = test()->compileService(releasedSpec())->toYaml()
        ."---\n".test()->compileRelease(releasedSpec())->toYaml();

    $golden = test()->golden('service-production');

    if (getenv('UPDATE_GOLDEN') === '1' || ! file_exists($golden)) {
        file_put_contents($golden, $yaml);
    }

    expect($yaml)->toBe(file_get_contents($golden));
});
