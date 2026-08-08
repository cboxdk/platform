<?php

declare(strict_types=1);

use Cbox\Platform\Service\ProcessSpec;
use Cbox\Platform\Service\ResourceRequirements;
use Cbox\Platform\Service\ServiceSpec;
use Cbox\Platform\Testing\SpecFactory;

function webContainer(ServiceSpec $spec): array
{
    foreach (test()->compileService($spec)->manifests as $manifest) {
        if ($manifest->kind === 'Deployment') {
            return $manifest->listItem(0, 'spec', 'template', 'spec', 'containers');
        }
    }

    return [];
}

it('sizes a service nobody has sized exactly as it was sized before', function (): void {
    // These values were inline in the compiler. Making them expressible must not
    // change what an existing service compiles to — the golden files say the
    // same thing, and this says why.
    expect(webContainer(SpecFactory::service())['resources'])
        ->toBe(['requests' => ['cpu' => '100m', 'memory' => '128Mi']]);
});

it('compiles what the customer actually asked for', function (): void {
    $container = webContainer(SpecFactory::service(resources: new ResourceRequirements(
        cpuRequest: '500m',
        memoryRequest: '1Gi',
        memoryLimit: '2Gi',
    )));

    expect($container['resources'])->toBe([
        'requests' => ['cpu' => '500m', 'memory' => '1Gi'],
        'limits' => ['memory' => '2Gi'],
    ]);
});

it('omits a block nobody filled rather than emitting an empty one', function (): void {
    // `resources: {requests: {}}` reads as configured and is not, and it shows
    // up in a plan as a change nobody made.
    expect(new ResourceRequirements(memoryLimit: '512Mi')->toArray())
        ->toBe(['limits' => ['memory' => '512Mi']])
        ->and(new ResourceRequirements()->toArray())->toBe([]);
});

it('gives every process the same size as the service it belongs to', function (): void {
    $spec = new ServiceSpec(
        serviceId: 'svc', organizationId: 'org', namespace: 'ns', name: 'web',
        image: 'img', port: 80, replicas: 1,
        processes: [new ProcessSpec('worker', ['php', 'artisan', 'queue:work'], 1)],
        resources: new ResourceRequirements(cpuRequest: '250m', memoryRequest: '512Mi'),
    );

    $worker = null;

    foreach (test()->compileService($spec)->manifests as $manifest) {
        if ($manifest->kind === 'Deployment' && $manifest->name === 'web-worker') {
            $worker = $manifest->listItem(0, 'spec', 'template', 'spec', 'containers');
        }
    }

    expect($worker['resources'])->toBe(['requests' => ['cpu' => '250m', 'memory' => '512Mi']]);
});

it('refuses to autoscale on a percentage of nothing', function (): void {
    // CPU utilization is measured against the REQUEST. With no request the
    // metric is unavailable, the HPA never acts, and the service reports
    // autoscaling as on while running a fixed count for ever. Refused, rather
    // than defaulted behind the customer's back on a workload they have
    // explicitly sized.
    $spec = new ServiceSpec(
        serviceId: 'svc', organizationId: 'org', namespace: 'ns', name: 'web',
        image: 'img', port: 80, replicas: 1,
        autoscaleMin: 2, autoscaleMax: 8, autoscaleCpuPercent: 70,
        resources: new ResourceRequirements(memoryRequest: '512Mi'),
    );

    expect(fn () => test()->compileService($spec))
        ->toThrow(LogicException::class, 'autoscales on CPU but sets no CPU request');
});

it('lets a sized service autoscale', function (): void {
    $spec = new ServiceSpec(
        serviceId: 'svc', organizationId: 'org', namespace: 'ns', name: 'web',
        image: 'img', port: 80, replicas: 1,
        autoscaleMin: 2, autoscaleMax: 8, autoscaleCpuPercent: 70,
        resources: new ResourceRequirements(cpuRequest: '250m'),
    );

    $kinds = array_map(fn ($m): string => $m->kind, test()->compileService($spec)->manifests);

    expect($kinds)->toContain('ScaledObject');
});

it('reads back what was stored', function (): void {
    $requirements = ResourceRequirements::fromArray([
        'cpu_request' => ' 250m ',
        'memory_request' => '512Mi',
        'memory_limit' => '',
    ]);

    expect($requirements->cpuRequest)->toBe('250m')
        ->and($requirements->memoryRequest)->toBe('512Mi')
        ->and($requirements->memoryLimit)->toBeNull()
        ->and($requirements->isEmpty())->toBeFalse()
        ->and(ResourceRequirements::fromArray([])->isEmpty())->toBeTrue();
});

it('refuses a name Kubernetes would reject, in the product\'s own words', function (string $name): void {
    // The two failures read completely differently: this one names the rule,
    // the API server's names a schema the customer never opted into — from
    // somewhere inside a deploy that had already started.
    expect(fn () => test()->compileService(SpecFactory::service(name: $name)))
        ->toThrow(LogicException::class, 'cannot be a Kubernetes object name');
})->with([
    'empty' => '',
    'uppercase' => 'Web',
    'underscore' => 'my_service',
    'leading hyphen' => '-web',
    'trailing hyphen' => 'web-',
    'a dot, which a Service may not have' => 'web.api',
    'a newline' => "web\nevil: true",
    'over 63 characters' => 'a-very-long-name-that-keeps-going-and-going-and-going-past-the-limit',
]);

it('refuses a process name on the same grounds, since it becomes an object too', function (): void {
    $spec = new ServiceSpec(
        serviceId: 'svc', organizationId: 'org', namespace: 'ns', name: 'web',
        image: 'img', port: 80, replicas: 1,
        processes: [new ProcessSpec('Queue_Worker', ['php'], 1)],
    );

    expect(fn () => test()->compileService($spec))->toThrow(LogicException::class);
});

it('accepts the names a customer actually uses', function (string $name): void {
    expect(test()->compileService(SpecFactory::service(name: $name))->manifests)->not->toBe([]);
})->with(['web', 'api-v2', 'worker2', 'a', str_repeat('a', 63)]);
