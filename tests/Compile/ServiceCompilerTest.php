<?php

declare(strict_types=1);

use Cbox\Platform\Compile\ServiceCompiler;
use Cbox\Platform\Manifest\ManifestSet;
use Cbox\Platform\Runtime\ZeropodSnapshotRuntime;
use Cbox\Platform\Service\ServiceSpec;

function fixtureSpec(array $domains = ['app.acme.example'], int $replicas = 2): ServiceSpec
{
    withSnapshotRuntime(null);

    return new ServiceSpec(
        serviceId: '01J0000000000000000000SVC1',
        organizationId: '01J0000000000000000000ORG1',
        namespace: 'cx-production-svc9k2',
        name: 'web',
        image: 'ghcr.io/acme/web:1.4.2',
        port: 3000,
        replicas: $replicas,
        env: ['APP_ENV' => 'production', 'LOG_LEVEL' => 'info'],
        domains: $domains,
    );
}

/**
 * The scale-to-zero fixture — same service, opted in, at the CRIU/zeropod tier
 * (a runtime class is configured) so the golden also pins the snapshot env and
 * runtimeClassName.
 */
function withSnapshotRuntime(?string $runtimeClass = 'zeropod'): void
{
    test()->compilingWithSnapshotRuntime($runtimeClass === null
        ? null
        : new ZeropodSnapshotRuntime($runtimeClass));
}

/** Compile only AFTER the fixture has declared the cell's runtime. */
function compileService(ServiceSpec $spec): ManifestSet
{
    return test()->compileService($spec);
}

function scaleToZeroSpec(bool $suspended = false, ?string $runtimeClass = 'zeropod'): ServiceSpec
{
    withSnapshotRuntime($runtimeClass);

    return new ServiceSpec(
        serviceId: '01J0000000000000000000SVC1',
        organizationId: '01J0000000000000000000ORG1',
        namespace: 'cx-production-svc9k2',
        name: 'web',
        image: 'ghcr.io/acme/web:1.4.2',
        port: 3000,
        replicas: 2,
        env: ['APP_ENV' => 'production', 'LOG_LEVEL' => 'info'],
        domains: ['app.acme.example'],
        scaleToZero: true,
        idleTimeoutSeconds: 120,
        suspended: $suspended,
    );
}

it('matches the golden manifest set byte for byte', function (): void {
    $yaml = compileService(fixtureSpec())->toYaml();
    $golden = test()->golden('service-basic');

    if (getenv('UPDATE_GOLDEN') === '1' || ! file_exists($golden)) {
        file_put_contents($golden, $yaml);
    }

    expect($yaml)->toBe(file_get_contents($golden));
});

it('is deterministic: same spec compiles to identical bytes and hashes', function (): void {
    $compiler = new ServiceCompiler(test()->target());

    $a = $compiler->compile(fixtureSpec());
    $b = $compiler->compile(fixtureSpec());

    expect($a->toYaml())->toBe($b->toYaml())
        ->and($a->hashes())->toBe($b->hashes());
});

it('labels every object as cortex-managed', function (): void {
    $set = compileService(fixtureSpec());

    foreach ($set->manifests as $manifest) {
        /** @var array<string, mixed> $metadata */
        $metadata = $manifest->body['metadata'];
        /** @var array<string, string> $labels */
        $labels = $metadata['labels'];

        expect($labels['platform.cbox.dk/managed'])->toBe('true')
            ->and($labels['platform.cbox.dk/service'])->toBe('01J0000000000000000000SVC1');
    }
});

it('emits namespace, deployment, and service — plus a route only with a domain', function (): void {
    $withDomain = compileService(fixtureSpec());
    $withoutDomain = compileService(fixtureSpec(domains: []));

    $kinds = static fn ($set): array => array_map(static fn ($m): string => $m->kind, $set->manifests);

    expect($kinds($withDomain))->toBe(['Namespace', 'Deployment', 'Service', 'PodDisruptionBudget', 'HTTPRoute'])
        ->and($kinds($withoutDomain))->toBe(['Namespace', 'Deployment', 'Service', 'PodDisruptionBudget']);
});

it('matches the scale-to-zero golden manifest set byte for byte', function (): void {
    $yaml = compileService(scaleToZeroSpec())->toYaml();
    $golden = test()->golden('service-scale-to-zero');

    if (getenv('UPDATE_GOLDEN') === '1' || ! file_exists($golden)) {
        file_put_contents($golden, $yaml);
    }

    expect($yaml)->toBe(file_get_contents($golden));
});

it('adds an HTTPScaledObject and routes through the KEDA interceptor on the cold-start tier', function (): void {
    $set = compileService(scaleToZeroSpec(runtimeClass: null));

    $kinds = array_map(static fn ($m): string => $m->kind, $set->manifests);
    expect($kinds)->toBe(['Namespace', 'Deployment', 'Service', 'HTTPScaledObject', 'ReferenceGrant', 'HTTPRoute']);

    $route = collect($set->manifests)->firstWhere('kind', 'HTTPRoute');
    /** @var array<string, mixed> $backend */
    $backend = $route->body['spec']['rules'][0]['backendRefs'][0];
    expect($backend['name'])->toBe('keda-add-ons-http-interceptor-proxy')
        ->and($backend['namespace'])->toBe('keda');

    // AND THE PERMISSION THAT BACKEND NEEDS. Without it the Gateway API refuses
    // the cross-namespace reference with ResolvedRefs=False RefNotPermitted, and
    // the service answers 500 to every request while reporting Accepted=True.
    // Measured on a cluster with KEDA installed and working.
    $grant = collect($set->manifests)->firstWhere('kind', 'ReferenceGrant');
    expect($grant->namespace)->toBe('keda')
        // Named for the SOURCE namespace, not the service: ten services in one
        // namespace need one grant, and ten identical objects with different
        // names would put nine of them in every plan for nothing.
        ->and($grant->name)->toBe('cbox-routes-from-cx-production-svc9k2')
        ->and($grant->body['spec']['from'][0]['namespace'])->toBe('cx-production-svc9k2')
        ->and($grant->body['spec']['to'][0]['name'])->toBe('keda-add-ons-http-interceptor-proxy');

    $scaler = collect($set->manifests)->firstWhere('kind', 'HTTPScaledObject');
    expect($scaler->body['spec']['scaledownPeriod'])->toBe(120)
        ->and($scaler->body['spec']['replicas'])->toBe(['min' => 0, 'max' => 2])
        ->and($scaler->body['spec']['hosts'])->toBe(['app.acme.example']);

    // The cold-start tier hands the count to KEDA from zero upward.
    $deployment = collect($set->manifests)->firstWhere('kind', 'Deployment');
    expect($deployment->body['spec']['replicas'])->toBe(0);
});

it('keeps the pod scheduled and forces snapshot-readiness env on the CRIU tier', function (): void {
    $set = compileService(scaleToZeroSpec());

    // No scaler: KEDA would delete the very pod the runtime snapshots. The route
    // goes straight to the workload — the restore happens on TCP connect.
    $kinds = array_map(static fn ($m): string => $m->kind, $set->manifests);
    expect($kinds)->toBe(['Namespace', 'Deployment', 'Service', 'HTTPRoute']);

    $route = collect($set->manifests)->firstWhere('kind', 'HTTPRoute');
    expect($route->body['spec']['rules'][0]['backendRefs'][0]['name'])->toBe('web');

    $deployment = collect($set->manifests)->firstWhere('kind', 'Deployment');
    /** @var array<string, mixed> $template */
    $template = $deployment->body['spec']['template'];
    /** @var array<string, mixed> $podSpec */
    $podSpec = $template['spec'];
    $envMap = collect($podSpec['containers'][0]['env'])->pluck('value', 'name')->all();

    expect($deployment->body['spec']['replicas'])->toBe(2) // the pod must exist to be snapshotted
        ->and($podSpec['runtimeClassName'])->toBe('zeropod')
        ->and($template['metadata']['annotations'])->toBe([
            'zeropod.ctrox.dev/container-names' => 'web',
            'zeropod.ctrox.dev/ports-map' => 'web=3000',
            'zeropod.ctrox.dev/scaledown-duration' => '120s',
        ])
        ->and($envMap['GODEBUG'])->toBe('multipathtcp=0')
        ->and($envMap['PHP_OPCACHE_JIT'])->toBe('off')
        ->and($envMap['APP_ENV'])->toBe('production');
});

it('pins a suspended service to zero and drops the scaler and interceptor route', function (): void {
    $set = compileService(scaleToZeroSpec(suspended: true));

    $kinds = array_map(static fn ($m): string => $m->kind, $set->manifests);
    expect($kinds)->toBe(['Namespace', 'Deployment', 'Service', 'HTTPRoute']);

    $deployment = collect($set->manifests)->firstWhere('kind', 'Deployment');
    expect($deployment->body['spec']['replicas'])->toBe(0);

    // The route falls back to the workload itself while suspended — no wake.
    $route = collect($set->manifests)->firstWhere('kind', 'HTTPRoute');
    expect($route->body['spec']['rules'][0]['backendRefs'][0]['name'])->toBe('web');
});

it('stays on the KEDA cold-start tier — no runtimeClass, no snapshot env — when no runtime class is set', function (): void {
    $deployment = collect(compileService(scaleToZeroSpec(runtimeClass: null))->manifests)
        ->firstWhere('kind', 'Deployment');

    /** @var array<string, mixed> $template */
    $template = $deployment->body['spec']['template'];
    /** @var array<string, mixed> $podSpec */
    $podSpec = $template['spec'];
    $envMap = collect($podSpec['containers'][0]['env'])->pluck('value', 'name')->all();

    expect($podSpec)->not->toHaveKey('runtimeClassName')
        ->and($template)->not->toHaveKey('annotations')
        ->and($envMap)->not->toHaveKey('GODEBUG')
        ->and($deployment->body['spec']['replicas'])->toBe(0); // KEDA still owns 0→N
});

it('a service with no domain still gets the CRIU tier — the restore needs no route', function (): void {
    withSnapshotRuntime('zeropod');
    $set = compileService(new ServiceSpec(
        serviceId: '01J0000000000000000000SVC1',
        organizationId: '01J0000000000000000000ORG1',
        namespace: 'cx-production-svc9k2',
        name: 'worker',
        image: 'ghcr.io/acme/worker:1.0.0',
        port: 9000,
        replicas: 1,
        domains: [],
        scaleToZero: true,
    ));

    $deployment = collect($set->manifests)->firstWhere('kind', 'Deployment');
    /** @var array<string, mixed> $template */
    $template = $deployment->body['spec']['template'];

    expect(array_map(static fn ($m): string => $m->kind, $set->manifests))
        ->toBe(['Namespace', 'Deployment', 'Service'])
        ->and($template['spec']['runtimeClassName'])->toBe('zeropod')
        ->and($template['metadata']['annotations']['zeropod.ctrox.dev/ports-map'])->toBe('worker=9000');
});

it('hash ignores key order but not values', function (): void {
    $set = compileService(fixtureSpec());
    $changed = test()->compileService(new ServiceSpec(
        serviceId: '01J0000000000000000000SVC1',
        organizationId: '01J0000000000000000000ORG1',
        namespace: 'cx-production-svc9k2',
        name: 'web',
        image: 'ghcr.io/acme/web:1.4.3', // bumped tag
        port: 3000,
        replicas: 2,
        env: ['APP_ENV' => 'production', 'LOG_LEVEL' => 'info'],
        domains: ['app.acme.example'],
    ));

    expect($changed->hashes()['Deployment/web'])->not->toBe($set->hashes()['Deployment/web'])
        ->and($changed->hashes()['Service/web'])->toBe($set->hashes()['Service/web']);
});

it('keeps a multi-replica service up through a drain', function (): void {
    // Draining is not exceptional on this platform — it is how a node is
    // replaced when it dies, how a pool is resized, and how a cluster is
    // upgraded. Without a budget the eviction API takes every replica of a
    // customer's service at once, and the service is down for as long as
    // rescheduling takes on a cluster where nothing looks broken.
    $budget = collect(test()->compileService(fixtureSpec())->manifests)
        ->firstWhere('kind', 'PodDisruptionBudget');

    expect($budget)->not->toBeNull()
        ->and($budget->body['spec']['maxUnavailable'])->toBe(1);

    // The SAME selector the Deployment uses. A budget selecting pods the
    // Deployment does not own protects the wrong set; one selecting fewer
    // protects nothing while looking like it does.
    $deployment = collect(test()->compileService(fixtureSpec())->manifests)
        ->firstWhere('kind', 'Deployment');

    expect($budget->body['spec']['selector'])->toBe($deployment->body['spec']['selector']);
});

it('gives a single replica no budget, so the node stays drainable', function (): void {
    // A budget over one pod cannot keep anything available, and it makes the
    // node UNDRAINABLE — one customer running a single replica would block the
    // node replacement a dead machine, a resize or an upgrade depends on, and
    // the operator draining it could not tell a stuck drain from a broken one.
    $manifests = test()->compileService(fixtureSpec(replicas: 1))->manifests;

    expect(collect($manifests)->firstWhere('kind', 'PodDisruptionBudget'))->toBeNull();
});
