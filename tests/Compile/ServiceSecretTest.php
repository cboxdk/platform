<?php

declare(strict_types=1);

use Cbox\Platform\Service\ServiceSpec;

const SECRET_VALUE = 'postgres://user:hunter2@db:5432/app';

function secretSpec(): ServiceSpec
{
    return new ServiceSpec(
        serviceId: '01J0000000000000000000SVC1',
        organizationId: '01J0000000000000000000ORG1',
        namespace: 'cx-production-db9k2',
        name: 'web',
        image: 'nginx:1.27-alpine',
        port: 80,
        replicas: 2,
        env: ['APP_ENV' => 'production'],
        envSecret: ['DATABASE_URL' => SECRET_VALUE],
    );
}

it('never writes a secret value into the pod spec', function (): void {
    $yaml = test()->compileService(secretSpec())->toYaml();

    /** @var array<string, mixed> $deployment */
    $deployment = collect(test()->compileService(secretSpec())->manifests)
        ->firstWhere('kind', 'Deployment')->body;

    $container = $deployment['spec']['template']['spec']['containers'][0];

    // Inlined, this value is readable by anyone who can run
    // `kubectl get deployment -o yaml` in the customer's own cluster — which,
    // for a cluster the customer hands to their whole team, is everyone.
    $encoded = json_encode($container, JSON_THROW_ON_ERROR);
    expect($encoded)->not->toContain('hunter2');

    // It is present, by reference.
    $names = array_column($container['env'], 'name');
    expect($names)->toContain('DATABASE_URL')->toContain('APP_ENV');

    $secretRef = collect($container['env'])->firstWhere('name', 'DATABASE_URL');
    expect($secretRef['valueFrom']['secretKeyRef'])->toBe(['name' => 'web-env', 'key' => 'DATABASE_URL']);

    // The non-secret one stays inline — a value that is safe to read costs
    // nothing to read, and a Secret per variable would be noise.
    $plain = collect($container['env'])->firstWhere('name', 'APP_ENV');
    expect($plain['value'])->toBe('production');

    // The Secret itself carries it, and comes first: a pod scheduled before
    // its Secret exists sits in CreateContainerConfigError.
    expect($yaml)->toContain('hunter2');

    $kinds = array_map(fn ($m): string => $m->kind, test()->compileService(secretSpec())->manifests);
    expect(array_search('Secret', $kinds, true))->toBeLessThan(array_search('Deployment', $kinds, true));
});

it('compiles no Secret when a service has none', function (): void {
    $spec = new ServiceSpec(
        serviceId: '01J0000000000000000000SVC1',
        organizationId: '01J0000000000000000000ORG1',
        namespace: 'cx-production-db9k2',
        name: 'web',
        image: 'nginx',
        port: 80,
        replicas: 1,
        env: ['APP_ENV' => 'production'],
    );

    $kinds = array_map(fn ($m): string => $m->kind, test()->compileService($spec)->manifests);

    expect($kinds)->not->toContain('Secret');
});
