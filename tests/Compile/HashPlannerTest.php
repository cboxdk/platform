<?php

declare(strict_types=1);

use Cbox\Platform\Plan\HashPlanner;
use Cbox\Platform\Plan\PlanAction;

it('plans create for everything on first deploy', function (): void {
    $set = test()->compileService(fixtureSpec());

    $plan = new HashPlanner()->plan($set, []);

    expect($plan->hasChanges())->toBeTrue()
        ->and($plan->summary())->toBe(['create' => 5, 'update' => 0, 'delete' => 0, 'unchanged' => 0]);
});

it('plans a clean no-op when nothing changed', function (): void {
    $set = test()->compileService(fixtureSpec());

    $plan = new HashPlanner()->plan($set, $set->hashes());

    expect($plan->hasChanges())->toBeFalse()
        ->and($plan->summary()['unchanged'])->toBe(5);
});

it('plans update only for the changed object', function (): void {
    $before = test()->compileService(fixtureSpec());
    $after = test()->compileService(fixtureSpec(domains: ['app.acme.example']));

    // Mutate one applied hash to simulate a previous image.
    $applied = $before->hashes();
    $applied['Deployment/web'] = 'stale-hash';

    $plan = new HashPlanner()->plan($after, $applied);

    expect($plan->summary())->toBe(['create' => 0, 'update' => 1, 'delete' => 0, 'unchanged' => 4]);
});

it('plans delete for objects that fell out of the desired set', function (): void {
    $withDomain = test()->compileService(fixtureSpec());
    $withoutDomain = test()->compileService(fixtureSpec(domains: []));

    $plan = new HashPlanner()->plan($withoutDomain, $withDomain->hashes());

    $deletes = array_values(array_filter($plan->entries, fn ($e): bool => $e->action === PlanAction::Delete));

    expect($deletes)->toHaveCount(1)
        ->and($deletes[0]->key)->toBe('HTTPRoute/web');
});
