<?php

declare(strict_types=1);

use Cbox\Platform\Service\ProcessSpec;
use Cbox\Platform\Service\ServiceSpec;

/**
 * A Laravel application is one image and several processes: web, a queue
 * worker, a scheduler. Expressing that meant three services with the same
 * image — each compiling a Service and an HTTPRoute a worker has no use for,
 * and each carrying its own copy of the environment and its own bindings,
 * which is how two processes of one application drift apart.
 */
function processSpec(array $processes, bool $suspended = false): ServiceSpec
{
    return new ServiceSpec(
        serviceId: '01J0000000000000000000SVC1',
        organizationId: '01J0000000000000000000ORG1',
        namespace: 'cx-production-db9k2',
        name: 'web',
        image: 'acme/app:1.4.0',
        port: 80,
        replicas: 2,
        env: ['APP_ENV' => 'production'],
        envSecret: ['APP_KEY' => 'base64:FIXTURE'],
        suspended: $suspended,
        processes: $processes,
    );
}

it('gives each process its own Deployment and nothing else', function (): void {
    $set = test()->compileService(processSpec([
        new ProcessSpec('worker', ['php', 'artisan', 'queue:work'], 3),
        new ProcessSpec('scheduler', ['php', 'artisan', 'schedule:work'], 1),
    ]));

    $deployments = collect($set->manifests)->where('kind', 'Deployment');

    expect($deployments->pluck('name')->all())->toBe(['web', 'web-worker', 'web-scheduler']);

    // Nothing dials a worker: a Service in front of a queue consumer is a load
    // balancer for traffic that never arrives.
    expect(collect($set->manifests)->where('kind', 'Service')->pluck('name')->all())->toBe(['web'])
        ->and(collect($set->manifests)->where('kind', 'HTTPRoute'))->toHaveCount(0);
});

it('shares the application\'s environment and secrets with every process', function (): void {
    $set = test()->compileService(processSpec([
        new ProcessSpec('worker', ['php', 'artisan', 'queue:work'], 1),
    ]));

    $worker = collect($set->manifests)->firstWhere('name', 'web-worker');
    $container = $worker->body['spec']['template']['spec']['containers'][0];
    $env = collect($container['env'])->keyBy('name');

    // Sharing rather than restating is the point: a worker needs the same
    // configuration as the web process, and a second copy is how they drift.
    expect($container['image'])->toBe('acme/app:1.4.0')
        ->and($env['APP_ENV']['value'])->toBe('production')
        ->and($env['APP_KEY']['valueFrom']['secretKeyRef']['name'])->toBe('web-env')
        ->and($container['command'])->toBe(['php', 'artisan', 'queue:work']);
});

it('does not give a worker a port or a readiness probe', function (): void {
    $set = test()->compileService(processSpec([
        new ProcessSpec('worker', ['php', 'artisan', 'queue:work'], 1),
    ]));

    $container = collect($set->manifests)->firstWhere('name', 'web-worker')
        ->body['spec']['template']['spec']['containers'][0];

    // A readiness probe on a process nothing routes to gates nothing, and a
    // port it never binds would fail one.
    expect($container)->not->toHaveKey('ports')
        ->and($container)->not->toHaveKey('readinessProbe');
});

it('selects each process separately, or they fight over each other\'s pods', function (): void {
    $set = test()->compileService(processSpec([
        new ProcessSpec('worker', ['php', 'artisan', 'queue:work'], 2),
    ]));

    $worker = collect($set->manifests)->firstWhere('name', 'web-worker');

    expect($worker->body['spec']['selector']['matchLabels'])
        ->toBe(['app.kubernetes.io/name' => 'web', 'cortex.io/process' => 'worker'])
        ->and($worker->body['spec']['replicas'])->toBe(2);
});

it('stops the workers when the application is suspended', function (): void {
    $set = test()->compileService(processSpec([
        new ProcessSpec('worker', ['php', 'artisan', 'queue:work'], 3),
    ], suspended: true));

    // Leaving a worker consuming while the web process is down is how a
    // "stopped" application keeps mutating data.
    expect(collect($set->manifests)->firstWhere('name', 'web-worker')->body['spec']['replicas'])->toBe(0);
});

it('compiles nothing extra for an application with one process', function (): void {
    $kinds = array_map(fn ($m): string => $m->kind, test()->compileService(processSpec([]))->manifests);

    expect(array_count_values($kinds)['Deployment'])->toBe(1);
});
