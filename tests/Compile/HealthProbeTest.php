<?php

declare(strict_types=1);

use Cbox\Platform\Capability\PlatformTarget;
use Cbox\Platform\Run\RunSpec;
use Cbox\Platform\Runtime\ZeropodSnapshotRuntime;
use Cbox\Platform\Service\HealthCheck;
use Cbox\Platform\Service\ProcessSpec;
use Cbox\Platform\Service\SecretReference;
use Cbox\Platform\Service\ServiceSpec;

/**
 * Readiness decides traffic, liveness decides restarts, startup decides when
 * either may begin. Before this, nothing was probed: a pod counted as ready the
 * moment its container started, so a rollout sent traffic to a process that
 * could not yet serve and stopped the old one that could.
 */
function probedSpec(?HealthCheck $health, array $extra = []): ServiceSpec
{
    return new ServiceSpec(...[
        'serviceId' => '01J0000000000000000000SVC1',
        'organizationId' => '01J0000000000000000000ORG1',
        'namespace' => 'cx-production-db9k2',
        'name' => 'web',
        'image' => 'acme/app:1.4.0',
        'port' => 80,
        'replicas' => 2,
        'secretRefs' => [new SecretReference('HEALTH_TOKEN', 'app', 'HEALTH_TOKEN')],
        'processes' => [new ProcessSpec('queue', ['php', 'artisan', 'queue:work'], 1)],
        'health' => $health,
        ...$extra,
    ]);
}

/** @return array<string, mixed> */
function probedContainer(ServiceSpec $spec, string $deployment = 'web'): array
{
    return test()->compileService($spec)->find('Deployment/'.$deployment)?->body['spec']['template']['spec']['containers'][0] ?? [];
}

it('compiles no probes when the service declares no health check', function (): void {
    expect(probedContainer(probedSpec(null)))
        ->not->toHaveKey('readinessProbe')
        ->not->toHaveKey('livenessProbe')
        ->not->toHaveKey('startupProbe');
});

it('probes readiness with the token from the container\'s own environment', function (): void {
    $container = probedContainer(probedSpec(new HealthCheck('/health/ready', '/up', 'HEALTH_TOKEN')));

    expect($container['readinessProbe'])->toBe([
        'exec' => ['command' => [
            'sh', '-c',
            'printf \'Authorization: Bearer %s\n\' "${HEALTH_TOKEN}"'
            .' | curl -fsS -o /dev/null --max-time 4 -H @- \'http://127.0.0.1:80/health/ready\'',
        ]],
        'periodSeconds' => 10,
        'timeoutSeconds' => 5,
        'failureThreshold' => 3,
        'successThreshold' => 1,
    ]);

    // THE VALUE IS NOWHERE IN THE SPEC. A static httpGet header would have put
    // it there; the exec probe names the variable and the shell reads it.
    expect(json_encode($container['readinessProbe'], JSON_THROW_ON_ERROR))->not->toContain('secretKeyRef');
});

it('probes liveness shallowly, and gates it behind a patient startup probe', function (): void {
    $container = probedContainer(probedSpec(new HealthCheck('/health/ready', '/up', 'HEALTH_TOKEN')));

    expect($container['livenessProbe'])->toBe([
        'httpGet' => ['path' => '/up', 'port' => 'http'],
        'periodSeconds' => 10,
        'timeoutSeconds' => 3,
        'failureThreshold' => 3,
    ]);

    // Five minutes to boot, asked every two seconds: a slow first start is not
    // killed, and a fast one is not made to wait for the next period.
    expect($container['startupProbe']['httpGet'])->toBe(['path' => '/up', 'port' => 'http'])
        ->and($container['startupProbe']['periodSeconds'] * $container['startupProbe']['failureThreshold'])->toBe(300);
});

it('probes readiness over plain HTTP when it needs no token', function (): void {
    $container = probedContainer(probedSpec(new HealthCheck('/ready')));

    expect($container['readinessProbe']['httpGet'])->toBe(['path' => '/ready', 'port' => 'http'])
        // No liveness path, no liveness probe — and no startup probe, which only
        // exists to hold liveness back.
        ->and($container)->not->toHaveKey('livenessProbe')
        ->and($container)->not->toHaveKey('startupProbe');
});

it('probes only the web process', function (): void {
    $spec = probedSpec(new HealthCheck('/health/ready', '/up', 'HEALTH_TOKEN'));

    // A queue worker listens on nothing: every probe would fail, and liveness
    // would restart a healthy worker every thirty seconds.
    expect(probedContainer($spec, 'web-queue'))
        ->not->toHaveKey('readinessProbe')
        ->not->toHaveKey('livenessProbe')
        ->not->toHaveKey('startupProbe');

    $run = test()->compileRun(new RunSpec('run-1', 'web-run-1', ['php', 'artisan', 'migrate'], $spec));
    $container = $run->manifests[0]->body['spec']['template']['spec']['containers'][0];

    expect($container)->not->toHaveKey('readinessProbe')
        ->not->toHaveKey('livenessProbe')
        ->not->toHaveKey('startupProbe');
});

it('refuses a token nothing in the service sets', function (): void {
    expect(fn () => test()->compileService(probedSpec(
        new HealthCheck('/health/ready', '/up', 'MISSING_TOKEN'),
    )))->toThrow(LogicException::class, 'nothing sets it');
});

it('refuses a token probe on the checkpointing tier', function (): void {
    test()->compilingFor(new PlatformTarget(snapshotRuntime: new ZeropodSnapshotRuntime('zeropod')));

    expect(fn () => test()->compileService(probedSpec(
        new HealthCheck('/health/ready', '/up', 'HEALTH_TOKEN'),
        ['scaleToZero' => true, 'domains' => ['app.acme.example']],
    )))->toThrow(LogicException::class, 'checkpointing');

    // Without a token the probe is an HTTP request the runtime handles like any other.
    expect(probedContainer(probedSpec(
        new HealthCheck('/ready', '/up'),
        ['scaleToZero' => true, 'domains' => ['app.acme.example']],
    )))->toHaveKey('readinessProbe');
});

it('refuses a health check it could not compile safely', function (string $readiness, string $liveness, string $token, string $message): void {
    expect(fn () => new HealthCheck($readiness, $liveness, $token))->toThrow(LogicException::class, $message);
})->with([
    'nothing to probe' => ['', '', '', 'readiness path, a liveness path, or both'],
    'relative path' => ['health', '', '', 'cannot be the readiness path'],
    'quote in the path' => ["/ready'; rm -rf /", '', '', 'cannot be the readiness path'],
    'dollar in the path' => ['/ready$(id)', '', '', 'cannot be the readiness path'],
    'space in the liveness path' => ['', '/u p', '', 'cannot be the liveness path'],
    'token variable with a hyphen' => ['/ready', '', 'HEALTH-TOKEN', 'health token'],
    'token without readiness' => ['', '/up', 'HEALTH_TOKEN', 'presented to the readiness path'],
]);
