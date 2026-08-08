<?php

declare(strict_types=1);

use Cbox\Platform\Capability\PlatformTarget;
use Cbox\Platform\Compile\ServiceCompiler;
use Cbox\Platform\Contracts\Compiler;
use Cbox\Platform\Contracts\Planner;
use Cbox\Platform\Manifest\Manifest;
use Cbox\Platform\Manifest\ManifestSet;
use Cbox\Platform\Plan\Plan;
use Cbox\Platform\Plan\PlanAction;
use Cbox\Platform\Plan\PlanEntry;
use Cbox\Platform\Service\ServiceSpec;
use Cbox\Platform\Testing\FakeCompiler;
use Cbox\Platform\Testing\FakePlanner;
use Cbox\Platform\Testing\FakeSnapshotRuntime;
use Cbox\Platform\Testing\SpecFactory;

/**
 * The support the package ships for testing the layer ABOVE it — exercised
 * here, because a fake nobody uses is a fake nobody has checked.
 *
 * `$deploy` below stands in for a consumer's deploy path: compile, plan, apply
 * only if something changed. That is the shape both Cbox Cortex and Cbox Local
 * have, and none of it should need real Kubernetes objects to test.
 */
function deploy(Compiler $compiler, Planner $planner, array $applied): string
{
    $desired = $compiler->compile(SpecFactory::service(replicas: 3));
    $plan = $planner->plan($desired, $applied);

    return $plan->hasChanges() ? 'applied' : 'no-op';
}

it('records what a consumer asked it to compile', function (): void {
    $compiler = new FakeCompiler;

    deploy($compiler, new FakePlanner, []);

    expect($compiler->compiled)->toHaveCount(1)
        ->and($compiler->lastSpec()->replicas)->toBe(3)
        ->and($compiler->lastSpec()->name)->toBe('web');
});

it('lets a consumer reach the branch a real planner makes tedious', function (): void {
    // "A deploy that changes nothing is a no-op" is a property worth keeping,
    // and the code guarding it is the hardest to reach with real inputs.
    expect(deploy(new FakeCompiler, FakePlanner::unchanged(), []))->toBe('no-op');
});

it('tells the truth by default, and only lies where a test asked it to', function (): void {
    $planner = new FakePlanner;

    expect(deploy(new FakeCompiler, $planner, []))->toBe('applied')
        ->and($planner->planned)->toHaveCount(1);

    $planner->returning(new Plan([new PlanEntry(PlanAction::Unchanged, 'Deployment/web', 'x')]));

    expect(deploy(new FakeCompiler, $planner, []))->toBe('no-op');
});

it('returns whatever set the test needs', function (): void {
    $canned = test()->compileService(SpecFactory::service());
    $compiler = new FakeCompiler()->returning($canned);

    expect($compiler->compile(SpecFactory::service())->hashes())->toBe($canned->hashes());
});

it('points the snapshot runtime either way, so both tiers are reachable', function (): void {
    $runtime = new FakeSnapshotRuntime;

    test()->compilingWithSnapshotRuntime($runtime);
    $warm = test()->compileService(SpecFactory::service(scaleToZero: true, domains: ['a.test']));

    test()->compilingWithSnapshotRuntime(FakeSnapshotRuntime::unavailable());
    $cold = test()->compileService(SpecFactory::service(scaleToZero: true, domains: ['a.test']));

    $kinds = fn (ManifestSet $set): array => array_map(fn ($m): string => $m->kind, $set->manifests);

    // The two tiers are mutually exclusive: the scaler deletes the pod a
    // checkpoint needs, so it must not appear on the warm tier.
    expect($kinds($warm))->not->toContain('HTTPScaledObject')
        ->and($kinds($cold))->toContain('HTTPScaledObject')
        ->and($runtime->annotated)->toHaveCount(1)
        ->and($runtime->annotated[0]['port'])->toBe(8080);
});

it('builds intent that actually compiles, with the one field a test cares about', function (): void {
    $set = test()->compileService(SpecFactory::service(
        name: 'api',
        domains: ['api.example.test'],
        bindings: [SpecFactory::binding()],
    ));

    expect(array_map(fn ($m): string => $m->kind, $set->manifests))
        ->toContain('Deployment', 'Service', 'HTTPRoute');
});

it('is fixed, not random — a varying fixture makes a golden impossible', function (): void {
    expect(SpecFactory::service()->serviceId)->toBe(SpecFactory::service()->serviceId)
        ->and(test()->compileService(SpecFactory::service())->toYaml())
        ->toBe(test()->compileService(SpecFactory::service())->toYaml());
});

/**
 * The extension model is DECORATION, not subclassing — and this proves it works.
 *
 * Nothing in the package is `final`, so a consumer can subclass anything. But
 * the compilers are private methods behind a two-method public surface, so a
 * subclass has almost nothing to override, and their internals are documented
 * as unsupported. Wrapping the contract is the supported path, so it is the one
 * with a test.
 */
it('lets a consumer wrap the compiler without reaching inside it', function (): void {
    $decorator = new class(new ServiceCompiler(new PlatformTarget)) implements Compiler
    {
        public function __construct(private readonly Compiler $inner) {}

        public function compile(ServiceSpec $spec): ManifestSet
        {
            $compiled = $this->inner->compile($spec);

            return new ManifestSet(array_map(
                static fn (Manifest $m): Manifest => new Manifest(
                    apiVersion: $m->apiVersion,
                    kind: $m->kind,
                    name: $m->name,
                    namespace: $m->namespace,
                    body: array_replace_recursive($m->body, [
                        'metadata' => ['labels' => ['example.com/team' => 'payments']],
                    ]),
                ),
                $compiled->manifests,
            ));
        }
    };

    $set = $decorator->compile(SpecFactory::service(domains: ['a.test']));

    foreach ($set->manifests as $manifest) {
        expect($manifest->body['metadata']['labels']['example.com/team'])->toBe('payments')
            // The package's own labels survive — a decorator adds, it does not
            // replace, and the managed label is what an admission policy keys on.
            ->and($manifest->body['metadata']['labels']['cortex.io/managed'])->toBe('true');
    }

    // And it is still a valid, hashable, plannable set.
    expect($set->hashes())->not->toBe([])
        ->and($set->toYaml())->toContain('example.com/team');
});

it('lets a consumer extend a spec to carry their own facts', function (): void {
    // Nothing is sealed. But note the shape a `readonly class` forces, because
    // "not final" promises less than it sounds like: a subclass must ALSO be
    // readonly, and a readonly property cannot have a default — so an extra
    // fact is threaded through the constructor rather than declared with a
    // value. That is a real constraint on extending a spec, and it is the price
    // of specs being immutable.
    $extended = new ExtendedServiceSpec(
        costCentre: 'payments',
        serviceId: 'svc', organizationId: 'org', namespace: 'ns', name: 'web',
        image: 'img', port: 80, replicas: 2,
    );

    $plain = new ServiceSpec(
        serviceId: 'svc', organizationId: 'org', namespace: 'ns', name: 'web',
        image: 'img', port: 80, replicas: 2,
    );

    expect($extended->costCentre)->toBe('payments')
        // And it compiles byte-identically to the plain spec — the compiler
        // reads the fields it knows and is untroubled by the ones it does not.
        ->and(test()->compileService($extended)->hashes())
        ->toBe(test()->compileService($plain)->hashes());
});

readonly class ExtendedServiceSpec extends ServiceSpec
{
    public function __construct(
        public string $costCentre,
        string $serviceId,
        string $organizationId,
        string $namespace,
        string $name,
        string $image,
        int $port,
        int $replicas,
    ) {
        parent::__construct(
            serviceId: $serviceId,
            organizationId: $organizationId,
            namespace: $namespace,
            name: $name,
            image: $image,
            port: $port,
            replicas: $replicas,
        );
    }
}
