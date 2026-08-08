<?php

declare(strict_types=1);

use Cbox\Platform\Capability\GatewayImplementation;
use Cbox\Platform\Capability\HttpAutoscaler;
use Cbox\Platform\Capability\PlatformTarget;
use Cbox\Platform\Database\DatabaseEngine;
use Cbox\Platform\Manifest\ManifestSet;
use Cbox\Platform\Run\RunSpec;
use Cbox\Platform\Service\ServiceSpec;
use Cbox\Platform\Testing\FakeSnapshotRuntime;
use Cbox\Platform\Testing\SpecFactory;

/**
 * Which Kubernetes APIs this package writes against, and which of them are
 * promises.
 *
 * A stable group (`v1`) will not change shape or disappear. An **alpha group
 * makes no promise at all**: it can change between two minor releases of the
 * add-on that owns it, and a cluster running the newer one simply refuses an
 * object written against the older. That is not a hypothetical — KEDA has sat on
 * `v1alpha1` for years and Envoy Gateway's policies move.
 *
 * So the alpha surface is PINNED HERE. Not to prevent it — three of these are
 * unavoidable if you want scale-to-zero and a real client address — but so that
 * adding a fourth is a deliberate act somebody had to edit this list for, rather
 * than a dependency discovered on the day a cluster upgrade breaks.
 *
 * Each of the three is owned by the capability that decides whether its add-on
 * is installed at all, so an installation on a newer version sets one value
 * rather than waiting for a release of this package.
 */
function everyApiVersion(): array
{
    $target = new PlatformTarget;
    $sets = [];

    $service = SpecFactory::service(domains: ['app.test'], bindings: [SpecFactory::binding()]);

    $sets[] = test()->compileService($service);
    $sets[] = test()->compileGateway(SpecFactory::gateway());
    $sets[] = test()->compileRun(new RunSpec(
        runId: 'run-1', jobName: 'web-run-1', command: ['php'], service: $service,
    ));

    foreach (DatabaseEngine::cases() as $engine) {
        $sets[] = test()->compileDatabase(SpecFactory::database(engine: $engine));
    }

    // Scale-to-zero on the cold-start tier, which is what emits KEDA's HTTP
    // objects; more than one replica, which is what emits a disruption budget;
    // and CPU autoscaling, which is what emits a ScaledObject. Each is
    // conditional, and the first version of this sweep missed two of them —
    // which is precisely how an API dependency goes unnoticed.
    $sets[] = test()->compileService(SpecFactory::service(scaleToZero: true, domains: ['app.test']));
    $sets[] = test()->compileService(SpecFactory::service(replicas: 3));
    $sets[] = test()->compileService(new ServiceSpec(
        serviceId: 'svc', organizationId: 'org', namespace: 'ns', name: 'web',
        image: 'img:1', port: 80, replicas: 2,
        autoscaleMin: 2, autoscaleMax: 8, autoscaleCpuPercent: 70,
    ));

    $versions = [];

    foreach ($sets as $set) {
        /** @var ManifestSet $set */
        foreach ($set->manifests as $manifest) {
            $versions[$manifest->apiVersion] = true;
        }
    }

    $found = array_keys($versions);
    sort($found);

    return $found;
}

it('writes against these APIs and no others', function (): void {
    // Adding a group here is fine. Doing it without noticing is not — which is
    // the whole reason the list is written down.
    expect(everyApiVersion())->toBe([
        'apps/v1',
        'batch/v1',
        'cert-manager.io/v1',
        'gateway.envoyproxy.io/v1alpha1',
        'gateway.networking.k8s.io/v1',
        'http.keda.sh/v1alpha1',
        'keda.sh/v1alpha1',
        'policy/v1',
        'postgresql.cnpg.io/v1',
        'v1',
    ]);
});

it('depends on exactly three unstable APIs, each owned by a capability', function (): void {
    $unstable = array_values(array_filter(
        everyApiVersion(),
        static fn (string $version): bool => str_contains($version, 'alpha') || str_contains($version, 'beta'),
    ));

    expect($unstable)->toBe([
        'gateway.envoyproxy.io/v1alpha1',   // GatewayImplementation
        'http.keda.sh/v1alpha1',            // HttpAutoscaler
        'keda.sh/v1alpha1',                 // HttpAutoscaler
    ]);
});

it('lets an installation move an unstable API without waiting for a release', function (): void {
    // The point of putting the version on the capability: a cluster that
    // upgrades KEDA is not blocked on this package cutting a version.
    test()->compilingFor(new PlatformTarget(
        httpAutoscaler: new HttpAutoscaler(
            scaledObjectApiVersion: 'keda.sh/v1',
            httpScaledObjectApiVersion: 'http.keda.sh/v1beta1',
        ),
        gateway: GatewayImplementation::envoyGateway(
            clientTrafficPolicyApiVersion: 'gateway.envoyproxy.io/v1',
        ),
    ));

    // Two specs, because the two KEDA objects come from different intent: a
    // cold-start wake emits the HTTPScaledObject, CPU autoscaling the ScaledObject.
    $waking = test()->compileService(SpecFactory::service(scaleToZero: true, domains: ['app.test']));
    $scaling = test()->compileService(new ServiceSpec(
        serviceId: 'svc', organizationId: 'org', namespace: 'ns', name: 'web',
        image: 'img:1', port: 80, replicas: 2,
        autoscaleMin: 2, autoscaleMax: 8, autoscaleCpuPercent: 70,
    ));
    $gateway = test()->compileGateway(SpecFactory::gateway());

    $versions = array_map(
        static fn ($m): string => $m->apiVersion,
        [...$waking->manifests, ...$scaling->manifests, ...$gateway->manifests],
    );

    expect($versions)->toContain('keda.sh/v1', 'http.keda.sh/v1beta1', 'gateway.envoyproxy.io/v1')
        ->and($versions)->not->toContain('keda.sh/v1alpha1');
});

it('emits no unstable API at all for a target without those add-ons', function (): void {
    // A target with a snapshot runtime never reaches the KEDA tier, and a
    // conformant gateway has no Envoy policy. What is left is entirely stable —
    // which is the honest answer to "what does this need from my cluster".
    test()->compilingFor(new PlatformTarget(
        snapshotRuntime: new FakeSnapshotRuntime,
        gateway: GatewayImplementation::conformant('nginx'),
    ));

    $versions = array_map(
        static fn ($m): string => $m->apiVersion,
        [
            ...test()->compileService(SpecFactory::service(scaleToZero: true, domains: ['a.test']))->manifests,
            ...test()->compileGateway(SpecFactory::gateway())->manifests,
        ],
    );

    foreach ($versions as $version) {
        expect($version)->not->toContain('alpha')
            ->and($version)->not->toContain('beta');
    }
});
