<?php

declare(strict_types=1);

use Cbox\Platform\Capability\Placement;
use Cbox\Platform\Capability\PlatformIdentity;
use Cbox\Platform\Capability\PlatformTarget;
use Cbox\Platform\Manifest\Labels;
use Cbox\Platform\Manifest\Manifest;
use Cbox\Platform\Manifest\ManifestSet;
use Cbox\Platform\Plan\FieldChange;
use Cbox\Platform\Runtime\NoSnapshotRuntime;
use Cbox\Platform\Service\FpmProfile;
use Cbox\Platform\Service\LifecycleState;
use Cbox\Platform\Service\OpcacheJit;
use Cbox\Platform\Service\RuntimeSettings;
use Cbox\Platform\Testing\SpecFactory;

/**
 * The parts of the public surface that coverage found untested.
 *
 * All of it ships and all of it is documented as supported, so "nothing has
 * ever called this" is not a state it should be in — three of these enums were
 * at zero.
 */
it('falls back to running for a lifecycle value it does not recognise', function (): void {
    // Reading intent out of storage: an unknown string must not become a third
    // state, and it must not throw on a row somebody hand-edited. Running is
    // the safe answer — a service nobody deliberately suspended stays up.
    expect(LifecycleState::fromString('running'))->toBe(LifecycleState::Running)
        ->and(LifecycleState::fromString('suspended'))->toBe(LifecycleState::Suspended)
        ->and(LifecycleState::fromString('resuming'))->toBe(LifecycleState::Running)
        ->and(LifecycleState::fromString(''))->toBe(LifecycleState::Running);
});

it('answers the one question the compiler asks a lifecycle', function (): void {
    expect(LifecycleState::Suspended->isSuspended())->toBeTrue()
        ->and(LifecycleState::Running->isSuspended())->toBeFalse();
});

it('gives every runtime choice a label a person can read', function (): void {
    // These reach a form. A case added without one renders as an empty option,
    // which is a choice nobody can make.
    foreach (FpmProfile::cases() as $case) {
        expect($case->label())->not->toBe('');
    }

    foreach (OpcacheJit::cases() as $case) {
        expect($case->label())->not->toBe('');
    }

    expect(FpmProfile::Medium->label())->toContain('default')
        ->and(OpcacheJit::Tracing->label())->toContain('default');
});

it('names the background processes as a person would', function (): void {
    // This is what the product SHOWS about a service, including the counts —
    // and it is the same set that decides whether the container ever idles.
    $settings = new RuntimeSettings(
        scheduler: true, horizon: true, reverb: true,
        queue: true, queueHigh: true, queueScale: 4, queueHighScale: 2,
    );

    expect($settings->backgroundProcesses())
        ->toBe(['scheduler', 'Horizon', 'Reverb', 'queue ×4', 'queue:high ×2'])
        ->and($settings->keepsContainerAwake())->toBeTrue();
});

it('names a worker without a count when nobody sized it', function (): void {
    expect(new RuntimeSettings(queue: true)->backgroundProcesses())->toBe(['queue'])
        ->and(new RuntimeSettings()->backgroundProcesses())->toBe([])
        // Caching config is not a process; it must not make the list, and it
        // must not keep the container awake.
        ->and(new RuntimeSettings(optimize: true)->backgroundProcesses())->toBe([]);
});

it('adds nothing at all to a pod when the target cannot snapshot', function (): void {
    // The default, and the whole point of it: scale-to-zero still works, it just
    // falls back to a cold start rather than claiming a warm one.
    $runtime = new NoSnapshotRuntime;

    expect($runtime->isAvailable())->toBeFalse()
        ->and($runtime->runtimeClassName())->toBeNull()
        ->and($runtime->annotations('web', 8080, 300))->toBe([])
        ->and($runtime->environment())->toBe([]);
});

it('finds an object by identity, and says so plainly when there is none', function (): void {
    $set = new ManifestSet([
        new Manifest('apps/v1', 'Deployment', 'web', 'ns', ['kind' => 'Deployment']),
        new Manifest('v1', 'Service', 'web', 'ns', ['kind' => 'Service']),
    ]);

    // Kind AND name: a Deployment and a Service both called `web` are two
    // different objects, and a lookup by name alone would return the wrong one.
    expect($set->find('Deployment/web')?->kind)->toBe('Deployment')
        ->and($set->find('Service/web')?->kind)->toBe('Service')
        ->and($set->find('Ingress/web'))->toBeNull();
});

it('renders every value shape a plan can meet', function (): void {
    expect(FieldChange::render(null))->toBe('null')
        ->and(FieldChange::render(true))->toBe('true')
        ->and(FieldChange::render(false))->toBe('false')
        ->and(FieldChange::render(3))->toBe('3')
        ->and(FieldChange::render('web'))->toBe('web')
        ->and(FieldChange::render([1, 2, 3]))->toBe('[3 items]')
        ->and(FieldChange::render(['a' => 1]))->toBe('{1 keys}')
        // A plan is diagnostic output. Meeting something it did not expect
        // should name it, not fail while somebody is trying to read a diff.
        ->and(FieldChange::render(new stdClass))->toBe('stdClass');
});

it('round-trips a manifest through storage without losing anything', function (): void {
    $manifest = new Manifest('apps/v1', 'Deployment', 'web', 'ns', [
        'kind' => 'Deployment',
        'spec' => ['replicas' => 2, 'template' => ['spec' => ['containers' => [['name' => 'web']]]]],
    ]);

    $restored = Manifest::fromArray($manifest->toArray());

    expect($restored->hash())->toBe($manifest->hash())
        ->and($restored->toArray())->toBe($manifest->toArray());
});

it('survives a stored manifest that is missing its fields', function (): void {
    // Applied state is a column somebody may have edited, and a plan that
    // crashes on it is a deploy nobody can run to fix it.
    $restored = Manifest::fromArray(['kind' => 'Deployment']);

    expect($restored->kind)->toBe('Deployment')
        ->and($restored->name)->toBe('')
        ->and($restored->body)->toBe([]);

    expect(ManifestSet::fromArray(['a' => 'not an array'])->manifests)->toBe([]);
});

it('labels under a prefix the vendor actually owns', function (): void {
    // A label prefix is a DNS subdomain, and the convention exists so two
    // vendors cannot collide inside one object. The previous default was
    // `cortex.io`, which Cbox does not own and a real company does.
    $labels = new PlatformIdentity()->labels(
        name: 'web',
        identity: ['service' => 'svc-1'],
    );

    expect($labels)->toHaveKey('platform.cbox.dk/managed')
        ->and($labels)->toHaveKey('platform.cbox.dk/service')
        ->and($labels['app.kubernetes.io/managed-by'])->toBe('cbox-platform')
        ->and(implode(' ', array_keys($labels)))->not->toContain('cortex.io');
});

it('lets an installation name itself, so no product is compiled into the output', function (): void {
    $identity = new PlatformIdentity(
        labelPrefix: 'platform.example.com',
        fieldManager: 'example-sync',
        resourcePrefix: 'ex',
    );

    expect($identity->label('service'))->toBe('platform.example.com/service')
        ->and($identity->name('gateway'))->toBe('ex-gateway')
        ->and($identity->role('cluster-reader'))->toBe('ex:cluster-reader')
        ->and($identity->labels('web', [])['app.kubernetes.io/managed-by'])->toBe('example-sync');
});

it('reads a version out of an image reference, or admits there is none', function (): void {
    $version = Labels::versionFrom(...);

    expect($version('ghcr.io/acme/web:1.4.2'))->toBe('1.4.2')
        ->and($version('nginx:1.27-alpine'))->toBe('1.27-alpine')
        // A registry port is not a tag. `registry:5000/app` used to read as one.
        ->and($version('registry:5000/app'))->toBeNull()
        ->and($version('nginx'))->toBeNull()
        // A digest is not a version, and would fail the apply anyway: 71
        // characters with a colon in it is not a legal label value.
        ->and($version('ghcr.io/acme/web@sha256:'.str_repeat('a', 64)))->toBeNull();
});

it('leaves out a label Kubernetes would refuse rather than failing the apply', function (): void {
    $labels = new PlatformIdentity()->labels(
        name: 'web',
        identity: [],
        component: str_repeat('a', 64),   // one over the limit
        version: 'not a version',         // spaces
        partOf: '',                       // says nothing
    );

    expect($labels)->not->toHaveKey('app.kubernetes.io/component')
        ->and($labels)->not->toHaveKey('app.kubernetes.io/version')
        ->and($labels)->not->toHaveKey('app.kubernetes.io/part-of');
});

it('places pods where the target says, not where the application asks', function (): void {
    // An application and its placement are two different designs. A single-node
    // cluster has nowhere to spread to; a cell has hosts. The application is
    // identical in both.
    $spread = new Placement;
    $flat = Placement::singleNode();

    expect($spread->constraints(['a' => 'b'])[0]['topologyKey'])->toBe('kubernetes.io/hostname')
        ->and($spread->constraints(['a' => 'b'])[0]['whenUnsatisfiable'])->toBe('ScheduleAnyway')
        ->and($flat->constraints(['a' => 'b']))->toBe([])
        ->and($flat->spreads())->toBeFalse();

    $dedicated = new Placement(
        topologyKey: 'topology.kubernetes.io/zone',
        strict: true,
        nodeSelector: ['pool' => 'memory'],
        tolerations: [['key' => 'dedicated', 'operator' => 'Exists']],
    );

    $fields = $dedicated->podFields(['a' => 'b']);

    expect($fields['topologySpreadConstraints'][0]['whenUnsatisfiable'])->toBe('DoNotSchedule')
        ->and($fields['nodeSelector'])->toBe(['pool' => 'memory'])
        ->and($fields['tolerations'])->toHaveCount(1);
});

it('compiles no spread constraint at all for a single-node target', function (): void {
    test()->compilingFor(new PlatformTarget(
        placement: Placement::singleNode(),
    ));

    $yaml = test()->compileService(SpecFactory::service())->toYaml();

    expect($yaml)->not->toContain('topologySpreadConstraints');
});
