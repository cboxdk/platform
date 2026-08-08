<?php

declare(strict_types=1);

use Cbox\Platform\Manifest\Manifest;
use Cbox\Platform\Manifest\ManifestSet;
use Cbox\Platform\Plan\FieldChangeKind;
use Cbox\Platform\Plan\HashPlanner;
use Cbox\Platform\Plan\ManifestDiff;
use Cbox\Platform\Plan\PlanAction;
use Cbox\Platform\Service\ServiceSpec;
use Cbox\Platform\Testing\SpecFactory;

/**
 * `~ spec.replicas 1 → 3` — the line the product's plan output is FOR.
 *
 * A hash comparison answers "did this object change", which is enough to decide
 * whether to apply and not enough for a person to approve. The detail costs the
 * previous body, so it is a separate call rather than a wider contract: a
 * consumer that does not retain bodies keeps the cheap plan unchanged.
 */
it('says which field moved and where it moved to', function (): void {
    $before = test()->compileService(SpecFactory::service(replicas: 1));
    $after = test()->compileService(SpecFactory::service(replicas: 3));

    $plan = new HashPlanner()->planAgainst($after, $before->hashes(), $before);

    $deployment = null;

    foreach ($plan->entries as $entry) {
        if ($entry->key === 'Deployment/web') {
            $deployment = $entry;
        }
    }

    expect($deployment->action)->toBe(PlanAction::Update)
        ->and($deployment->isDetailed())->toBeTrue();

    $replicas = null;

    foreach ($deployment->changes as $change) {
        if ($change->path === 'spec.replicas') {
            $replicas = $change;
        }
    }

    expect($replicas)->not->toBeNull()
        ->and($replicas->kind)->toBe(FieldChangeKind::Changed)
        ->and($replicas->before)->toBe('1')
        ->and($replicas->after)->toBe('3');
});

it('reports the same actions as the cheap plan, only with more to say', function (): void {
    $before = test()->compileService(SpecFactory::service(replicas: 1, domains: ['a.test']));
    $after = test()->compileService(SpecFactory::service(replicas: 3));

    $cheap = new HashPlanner()->plan($after, $before->hashes());
    $detailed = new HashPlanner()->planAgainst($after, $before->hashes(), $before);

    $actions = fn ($plan): array => array_map(
        fn ($e): string => $e->action->value.' '.$e->key,
        $plan->entries,
    );

    expect($actions($detailed))->toBe($actions($cheap))
        ->and($detailed->summary())->toBe($cheap->summary());
});

it('adds nothing to a create, a delete or an unchanged object', function (): void {
    $before = test()->compileService(SpecFactory::service(domains: ['a.test']));
    $after = test()->compileService(SpecFactory::service());

    foreach (new HashPlanner()->planAgainst($after, $before->hashes(), $before)->entries as $entry) {
        if ($entry->action !== PlanAction::Update) {
            expect($entry->changes)->toBe([]);
        }
    }
});

it('names an added and a removed field for what they are', function (): void {
    $changes = new ManifestDiff()->compare(
        ['spec' => ['replicas' => 1, 'paused' => true]],
        ['spec' => ['replicas' => 1, 'strategy' => 'RollingUpdate']],
    );

    $byPath = [];

    foreach ($changes as $change) {
        $byPath[$change->path] = $change;
    }

    expect(array_keys($byPath))->toBe(['spec.paused', 'spec.strategy'])
        ->and($byPath['spec.paused']->kind)->toBe(FieldChangeKind::Removed)
        ->and($byPath['spec.paused']->before)->toBe('true')
        ->and($byPath['spec.paused']->after)->toBeNull()
        ->and($byPath['spec.strategy']->kind)->toBe(FieldChangeKind::Added)
        ->and($byPath['spec.strategy']->after)->toBe('RollingUpdate');
});

it('summarises a container rather than drowning the line that mattered', function (): void {
    // A list of maps printed in full turns one useful line into forty. The plan
    // says the shape changed and how big it is; the YAML is there for the rest.
    $changes = new ManifestDiff()->compare(
        ['spec' => ['containers' => [['name' => 'web']]]],
        ['spec' => ['containers' => [['name' => 'web'], ['name' => 'sidecar']]]],
    );

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->path)->toBe('spec.containers')
        ->and($changes[0]->before)->toBe('[1 items]')
        ->and($changes[0]->after)->toBe('[2 items]');
});

it('is deterministic and ordered, so a plan does not reshuffle between refreshes', function (): void {
    $a = new ManifestDiff()->compare(['z' => 1, 'a' => 1], ['z' => 2, 'a' => 2]);
    $b = new ManifestDiff()->compare(['a' => 1, 'z' => 1], ['a' => 2, 'z' => 2]);

    expect(array_map(fn ($c): string => $c->path, $a))->toBe(['a', 'z'])
        ->and(array_map(fn ($c): string => $c->path, $b))->toBe(['a', 'z']);
});

it('survives the round trip a consumer stores it through', function (): void {
    $compiled = test()->compileService(SpecFactory::service(replicas: 2));

    $rehydrated = ManifestSet::fromArray($compiled->toArray());

    // Retaining and reloading must not itself look like a change, or every
    // deploy after a restart would show a diff nobody made.
    expect($rehydrated->hashes())->toBe($compiled->hashes())
        ->and($rehydrated->toYaml())->toBe($compiled->toYaml())
        ->and(new HashPlanner()->planAgainst($compiled, $rehydrated->hashes(), $rehydrated)->hasChanges())->toBeFalse();
});

it('never prints secret material into a plan', function (): void {
    // A plan is rendered into a browser, an API response and a log. A field diff
    // that printed `stringData.APP_KEY old → new` would publish the customer's
    // key to all three — and this package's own security policy names exactly
    // that as the report it most wants to receive.
    $before = test()->compileService(SpecFactory::service(env: [], domains: []));
    $after = test()->compileService(new ServiceSpec(
        serviceId: 'svc', organizationId: 'org', namespace: 'ns', name: 'web',
        image: 'img', port: 80, replicas: 1,
        envSecret: ['APP_KEY' => 'ROTATED-SECRET-VALUE'],
    ));

    $plan = new HashPlanner()->planAgainst($after, $before->hashes(), $before);

    $printed = json_encode(array_map(
        static fn ($e): array => array_map(
            static fn ($c): array => [$c->path, $c->before, $c->after],
            $e->changes,
        ),
        $plan->entries,
    ), JSON_THROW_ON_ERROR);

    expect($printed)->not->toContain('ROTATED-SECRET-VALUE');
});

it('redacts the value of a Secret while keeping the key that changed', function (): void {
    $secret = fn (string $value): ManifestSet => new ManifestSet([
        new Manifest(
            apiVersion: 'v1', kind: 'Secret', name: 'web-env', namespace: 'ns',
            body: ['kind' => 'Secret', 'stringData' => ['APP_KEY' => $value]],
        ),
    ]);

    $before = $secret('old-key');
    $after = $secret('new-key');

    $plan = new HashPlanner()->planAgainst($after, $before->hashes(), $before);

    $change = $plan->entries[0]->changes[0];

    // The path survives, because a key NAME is not secret and "this key
    // changed" is exactly what a plan should say.
    expect($change->path)->toBe('stringData.APP_KEY')
        ->and($change->before)->toBe(ManifestDiff::REDACTED)
        ->and($change->after)->toBe(ManifestDiff::REDACTED);
});

it('still reports detail for the objects around a Secret', function (): void {
    // Redaction must not cost the rest of the plan its usefulness.
    $before = test()->compileService(SpecFactory::service(replicas: 1));
    $after = test()->compileService(SpecFactory::service(replicas: 4));

    $plan = new HashPlanner()->planAgainst($after, $before->hashes(), $before);

    $paths = [];

    foreach ($plan->entries as $entry) {
        foreach ($entry->changes as $change) {
            $paths[] = $change->path;
        }
    }

    expect($paths)->toContain('spec.replicas');
});

it('gives no detail for an object whose body was never retained', function (): void {
    // A consumer retains bodies per resource — and must not retain them for
    // Secrets at all. The hashes still decide the action; only the detail is
    // absent. Diffing against an empty body instead would report every field
    // the object has as newly added.
    $before = test()->compileService(SpecFactory::service(replicas: 1));
    $after = test()->compileService(SpecFactory::service(replicas: 4));

    $plan = new HashPlanner()->planAgainst($after, $before->hashes(), new ManifestSet([]));

    $updates = array_values(array_filter(
        $plan->entries,
        static fn ($e): bool => $e->action === PlanAction::Update,
    ));

    expect($updates)->not->toBe([])
        ->and($updates[0]->changes)->toBe([])
        // The action is unchanged: hashes decide it, bodies only decorate it.
        ->and($plan->summary())->toBe(
            new HashPlanner()->plan($after, $before->hashes())->summary(),
        );
});
