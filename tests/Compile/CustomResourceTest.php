<?php

declare(strict_types=1);

use Cbox\Platform\Capability\CustomResourcePolicy;
use Cbox\Platform\Capability\PlatformTarget;
use Cbox\Platform\Plan\HashPlanner;
use Cbox\Platform\Plan\PlanAction;
use Cbox\Platform\Service\CustomResource;
use Cbox\Platform\Service\ServiceSpec;

/**
 * Objects the platform does not model, deployed with a service.
 *
 * The POLICY is the consumer's — a hosted multi-tenant control plane and a
 * developer's own kind cluster need opposite answers, and baking either in would
 * make this one product's package. The INVARIANTS are not policy and hold
 * whatever the policy says.
 */
function withCustomResources(array $resources, ?CustomResourcePolicy $policy = null): ServiceSpec
{
    test()->compilingFor(new PlatformTarget(
        customResources: $policy ?? CustomResourcePolicy::unrestricted(),
    ));

    return new ServiceSpec(
        serviceId: 'svc', organizationId: 'org', namespace: 'cx-prod', name: 'web',
        image: 'img:1', port: 80, replicas: 1,
        customResources: $resources,
    );
}

function sealedSecret(string $name = 'db-password'): CustomResource
{
    return new CustomResource(
        apiVersion: 'bitnami.com/v1alpha1',
        kind: 'SealedSecret',
        name: $name,
        body: ['spec' => ['encryptedData' => ['password' => 'AgB...']]],
    );
}

it('carries the object, untouched where it matters', function (): void {
    $set = test()->compileService(withCustomResources([sealedSecret()]));

    $sealed = $set->find('SealedSecret/db-password');

    expect($sealed)->not->toBeNull()
        ->and($sealed->apiVersion)->toBe('bitnami.com/v1alpha1')
        // What the object is actually FOR is none of the platform's business.
        ->and($sealed->body['spec']['encryptedData']['password'])->toBe('AgB...');
});

it('denies everything by default, because a library must not ship an open door', function (): void {
    // A consumer one forgotten setting away from arbitrary objects in a shared
    // cluster is the wrong default for a package two products embed.
    expect(new PlatformTarget()->customResources->allowsNothing())->toBeTrue();

    expect(fn () => test()->compileService(
        withCustomResources([sealedSecret()], CustomResourcePolicy::forbidden()),
    ))->toThrow(LogicException::class, 'does not allow custom resources');
});

it('allows the groups an installation has decided to trust', function (): void {
    $policy = CustomResourcePolicy::allowingGroups(['bitnami.com']);

    expect(test()->compileService(withCustomResources([sealedSecret()], $policy))->find('SealedSecret/db-password'))
        ->not->toBeNull();

    // And nothing else. A group is the unit an operator installs as, so trusting
    // one is a decision somebody made rather than a list that drifts.
    $other = new CustomResource(apiVersion: 'acid.zalan.do/v1', kind: 'postgresql', name: 'rogue');

    expect(fn () => test()->compileService(withCustomResources([$other], $policy)))
        ->toThrow(LogicException::class, 'which this installation does not allow');
});

it('puts the object in the environment namespace whatever it asked for', function (): void {
    // A resource that could name its own namespace is a tenancy escape wearing a
    // feature's clothes. Not policy — an invariant.
    $escaping = new CustomResource(
        apiVersion: 'bitnami.com/v1alpha1',
        kind: 'SealedSecret',
        name: 'escape',
        body: ['metadata' => ['namespace' => 'kube-system'], 'spec' => []],
    );

    $sealed = test()->compileService(withCustomResources([$escaping]))->find('SealedSecret/escape');

    expect($sealed->namespace)->toBe('cx-prod')
        ->and($sealed->body['metadata']['namespace'])->toBe('cx-prod');
});

it('stamps its own labels and refuses to let the object claim them', function (): void {
    // The tenant's admission policy keys on the managed label to decide who may
    // write to what. An object carrying a forged one would impersonate a
    // platform object and pass that check.
    $forging = new CustomResource(
        apiVersion: 'bitnami.com/v1alpha1',
        kind: 'SealedSecret',
        name: 'forger',
        body: ['metadata' => ['labels' => [
            'platform.cbox.dk/managed' => 'false',
            'platform.cbox.dk/service' => 'somebody-elses-service',
            'mine' => 'kept',
        ]]],
    );

    $labels = test()->compileService(withCustomResources([$forging]))
        ->find('SealedSecret/forger')->body['metadata']['labels'];

    expect($labels['platform.cbox.dk/managed'])->toBe('true')
        ->and($labels['platform.cbox.dk/service'])->toBe('svc')
        ->and($labels['app.kubernetes.io/component'])->toBe('custom')
        // Its own labels survive; only the platform's are taken back.
        ->and($labels['mine'])->toBe('kept');
});

it('refuses a name the platform already compiled', function (): void {
    // One of them would silently overwrite the other, and which one depends on
    // emission order — which is not a thing anybody should have to reason about.
    $collision = new CustomResource(apiVersion: 'v1', kind: 'Service', name: 'web');

    expect(fn () => test()->compileService(withCustomResources([$collision])))
        ->toThrow(LogicException::class, 'cannot take the name of an object the platform owns');
});

it('plans and prunes them like everything else', function (): void {
    // The whole reason to carry them rather than let somebody kubectl apply: an
    // object applied by hand is invisible to plan/diff and never pruned.
    $before = test()->compileService(withCustomResources([sealedSecret()]));
    $after = test()->compileService(withCustomResources([]));

    $plan = new HashPlanner()->plan($after, $before->hashes());

    $deleted = array_values(array_filter(
        $plan->entries,
        static fn ($e): bool => $e->action === PlanAction::Delete,
    ));

    expect(array_map(static fn ($e): string => $e->key, $deleted))->toBe(['SealedSecret/db-password']);
});

it('refuses an object that could never be applied', function (): void {
    expect(fn () => new CustomResource(apiVersion: '', kind: 'SealedSecret', name: 'x'))
        ->toThrow(LogicException::class)
        ->and(fn () => new CustomResource(apiVersion: 'v1', kind: 'Secret', name: ''))
        ->toThrow(LogicException::class);
});
