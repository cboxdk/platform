<?php

declare(strict_types=1);

use Cbox\Platform\Database\BackupStorage;
use Cbox\Platform\Database\DatabaseEngine;
use Cbox\Platform\Database\DatabaseSpec;
use Cbox\Platform\Run\RunSpec;
use Cbox\Platform\Service\RegistrySpec;
use Cbox\Platform\Service\ServiceSpec;

/**
 * A credential appears in exactly one kind of object, and that kind is Secret.
 *
 * This is the property everything downstream leans on. The apply layer decides
 * what to persist by KIND — Cbox Cortex retains a compiled body for a field
 * diff and refuses to retain a Secret's — and that rule is only safe while a
 * non-Secret cannot hold a credential. Asserted here rather than in a consumer,
 * because it is a property of what this package emits.
 */
const SECRETS = [
    'env' => 'ENV-SECRET-VALUE',
    'registry' => 'REGISTRY-PASSWORD',
    'database' => 'DB-PASSWORD-VALUE',
    'storage key' => 'S3-SECRET-KEY',
    'storage id' => 'AKIA-ACCESS-KEY-ID',
];

/** @return array<string, string> every non-Secret body, keyed by identity */
function bodiesOutsideSecrets(iterable $manifests): array
{
    $bodies = [];

    foreach ($manifests as $manifest) {
        if ($manifest->kind === 'Secret') {
            continue;
        }

        $bodies[$manifest->key()] = json_encode($manifest->body, JSON_THROW_ON_ERROR);
    }

    return $bodies;
}

it('keeps a service credential out of every object that is not a Secret', function (): void {
    $set = test()->compileService(new ServiceSpec(
        serviceId: 'svc', organizationId: 'org', namespace: 'ns', name: 'web',
        image: 'img', port: 80, replicas: 1,
        envSecret: ['APP_KEY' => SECRETS['env']],
        registry: new RegistrySpec(server: 'ghcr.io', username: 'ci', password: SECRETS['registry']),
        domains: ['app.test'],
    ));

    foreach (bodiesOutsideSecrets($set->manifests) as $key => $body) {
        expect($body)->not->toContain(SECRETS['env'], $key)
            ->and($body)->not->toContain(SECRETS['registry'], $key);
    }

    // And it IS in a Secret — a test that passes because nothing was compiled
    // would be worse than no test.
    expect($set->toYaml())->toContain(SECRETS['env']);
});

it('keeps a database and its backup credentials out of everything but a Secret', function (
    DatabaseEngine $engine,
): void {
    $set = test()->compileDatabase(new DatabaseSpec(
        databaseId: 'db', organizationId: 'org', namespace: 'ns', name: 'primary',
        engine: $engine, version: $engine->defaultVersion(), instances: 1, storageSize: '10Gi',
        backupSchedule: '0 2 * * *',
        password: $engine->needsPassword() ? SECRETS['database'] : null,
        backupStorage: new BackupStorage(
            bucket: 'backups', endpoint: 'https://s3.test', region: 'eu-central-1',
            accessKeyId: SECRETS['storage id'], secretAccessKey: SECRETS['storage key'],
            retainDays: 14,
        ),
    ));

    foreach (bodiesOutsideSecrets($set->manifests) as $key => $body) {
        foreach (SECRETS as $what => $value) {
            expect($body)->not->toContain($value, "{$key} holds the {$what}");
        }
    }
})->with([
    'postgres' => DatabaseEngine::Postgres,
    'percona' => DatabaseEngine::Percona,
    'valkey' => DatabaseEngine::Valkey,
]);

it('exercises every compiler, so a new one cannot slip past the sweep', function (): void {
    // The sweep above is only as good as its coverage. If a compiler is added
    // and never compiled here, its output is unexamined — and the apply layer's
    // "Secrets are the only carrier" rule quietly stops being true.
    $compilers = array_map(
        static fn (string $path): string => basename($path, '.php'),
        glob(__DIR__.'/../../src/Compile/*.php') ?: [],
    );

    $swept = [
        'ServiceCompiler',            // above
        'EngineDatabaseCompiler',     // above, all three engines
        'CnpgDatabaseCompiler',
        'StatefulDatabaseCompiler',
        'BackupCompiler',             // reached through the database sweep
        'RunJobCompiler',             // derives its container from the Deployment
        'EnvironmentGatewayCompiler', // holds no credential at all
        'CustomerAccessCompiler',     // RBAC only
    ];

    expect(array_values(array_diff($compilers, $swept)))->toBe([]);
});

it('carries a run no credential its service does not already have', function (): void {
    // A run reuses the Deployment's compiled container, so it inherits the
    // secretKeyRef rather than a copied value — and this is what proves it.
    $service = new ServiceSpec(
        serviceId: 'svc', organizationId: 'org', namespace: 'ns', name: 'web',
        image: 'img', port: 80, replicas: 1,
        envSecret: ['APP_KEY' => SECRETS['env']],
    );

    $set = test()->compileRun(new RunSpec(
        runId: 'run-1', jobName: 'web-run-1', command: ['php', 'artisan', 'migrate'], service: $service,
    ));

    foreach (bodiesOutsideSecrets($set->manifests) as $key => $body) {
        expect($body)->not->toContain(SECRETS['env'], $key);
    }
});
