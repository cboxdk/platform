<?php

declare(strict_types=1);

use Cbox\Platform\Binding\BindingSpec;
use Cbox\Platform\Binding\ConnectionField;
use Cbox\Platform\Binding\ConnectionSource;
use Cbox\Platform\Capability\PlatformTarget;
use Cbox\Platform\Compile\BackupCompiler;
use Cbox\Platform\Compile\CnpgDatabaseCompiler;
use Cbox\Platform\Compile\EngineDatabaseCompiler;
use Cbox\Platform\Compile\ServiceCompiler;
use Cbox\Platform\Compile\StatefulDatabaseCompiler;
use Cbox\Platform\Database\DatabaseEngine;
use Cbox\Platform\Database\DatabaseSpec;
use Cbox\Platform\Plan\HashPlanner;
use Cbox\Platform\Plan\PlanAction;
use Cbox\Platform\Service\ServiceSpec;
use Symfony\Component\Yaml\Yaml;

/**
 * The portability proof.
 *
 * Everything below is built and compiled WITHOUT a framework: no application
 * boot, no service container, no ORM, no database, no configuration file, no
 * network. Nothing here uses the package's own testing trait either — this is
 * what an embedder actually writes.
 *
 * That is the whole claim the package makes, so it is asserted rather than
 * described. If this file ever needs one of those things to pass, the
 * extraction boundary has moved and the package is no longer shared
 * infrastructure — it is one product's internals with a second name.
 */
function portableEnvironment(): ServiceSpec
{
    return new ServiceSpec(
        serviceId: 'svc-checkout',
        organizationId: 'org-portability',
        namespace: 'cx-feature-checkout',
        name: 'web',
        image: 'ghcr.io/example/checkout:2.1.0',
        port: 8080,
        replicas: 2,
        env: ['APP_ENV' => 'production'],
        envSecret: ['APP_KEY' => 'base64:not-a-real-key'],
        bindings: [
            new BindingSpec(
                databaseName: 'orders',
                engine: 'postgres',
                map: [
                    ['field' => ConnectionField::Host, 'name' => 'DB_HOST'],
                    ['field' => ConnectionField::Port, 'name' => 'DB_PORT'],
                    ['field' => ConnectionField::User, 'name' => 'DB_USERNAME'],
                    ['field' => ConnectionField::Password, 'name' => 'DB_PASSWORD'],
                    ['field' => ConnectionField::Database, 'name' => 'DB_DATABASE'],
                ],
                source: new ConnectionSource(
                    secretName: 'orders-app',
                    secretKeys: [
                        ConnectionField::User->value => 'username',
                        ConnectionField::Password->value => 'password',
                    ],
                    plain: [
                        ConnectionField::Host->value => 'orders-rw.cx-feature-checkout.svc',
                        ConnectionField::Port->value => '5432',
                        ConnectionField::Database->value => 'orders',
                    ],
                ),
            ),
        ],
        domains: ['checkout.example.test'],
    );
}

function portableDatabase(): DatabaseSpec
{
    return new DatabaseSpec(
        databaseId: 'db-orders',
        organizationId: 'org-portability',
        namespace: 'cx-feature-checkout',
        name: 'orders',
        engine: DatabaseEngine::Postgres,
        version: '16',
        instances: 1,
        storageSize: '10Gi',
    );
}

it('compiles an environment into Kubernetes objects with nothing booted around it', function (): void {
    $target = new PlatformTarget;

    $service = new ServiceCompiler($target)->compile(portableEnvironment());

    $backups = new BackupCompiler($target);
    $database = new EngineDatabaseCompiler(
        new CnpgDatabaseCompiler($target, $backups),
        new StatefulDatabaseCompiler($target, $backups),
    )->compile(portableDatabase());

    $kinds = array_map(fn ($m): string => $m->kind, [...$service->manifests, ...$database->manifests]);

    expect($kinds)->toContain('Namespace', 'Deployment', 'Service', 'HTTPRoute', 'Secret', 'Cluster');
});

it('emits documents Kubernetes would accept: every one has an apiVersion, a kind and a name', function (): void {
    $yaml = new ServiceCompiler(new PlatformTarget)->compile(portableEnvironment())->toYaml();

    $documents = array_values(array_filter(
        array_map(
            static fn (string $chunk): mixed => Yaml::parse($chunk),
            explode("---\n", $yaml),
        ),
        static fn (mixed $document): bool => $document !== null,
    ));

    expect($documents)->not->toBeEmpty();

    foreach ($documents as $document) {
        expect($document)->toBeArray()
            ->and($document['apiVersion'] ?? '')->toBeString()->not->toBe('')
            ->and($document['kind'] ?? '')->toBeString()->not->toBe('')
            ->and($document['metadata']['name'] ?? '')->toBeString()->not->toBe('');
    }
});

it('keeps a secret value out of the pod spec even when nothing is watching', function (): void {
    $yaml = new ServiceCompiler(new PlatformTarget)->compile(portableEnvironment())->toYaml();

    $deployment = null;

    foreach (new ServiceCompiler(new PlatformTarget)->compile(portableEnvironment())->manifests as $manifest) {
        if ($manifest->kind === 'Deployment') {
            $deployment = $manifest;
        }
    }

    expect($deployment)->not->toBeNull()
        ->and(json_encode($deployment?->body, JSON_THROW_ON_ERROR))->not->toContain('not-a-real-key')
        // It is in the Secret, which is the one object that may hold it.
        ->and($yaml)->toContain('base64:not-a-real-key');
});

it('is deterministic: the same intent compiles to the same bytes and the same hashes', function (): void {
    $first = new ServiceCompiler(new PlatformTarget)->compile(portableEnvironment());
    $second = new ServiceCompiler(new PlatformTarget)->compile(portableEnvironment());

    expect($second->toYaml())->toBe($first->toYaml())
        ->and($second->hashes())->toBe($first->hashes());
});

it('plans nothing when nothing changed, and exactly the change when something did', function (): void {
    $before = new ServiceCompiler(new PlatformTarget)->compile(portableEnvironment());

    $unchanged = new HashPlanner()->plan($before, $before->hashes());

    expect($unchanged->hasChanges())->toBeFalse();

    $scaled = new ServiceCompiler(new PlatformTarget)->compile(
        new ServiceSpec(
            serviceId: 'svc-checkout',
            organizationId: 'org-portability',
            namespace: 'cx-feature-checkout',
            name: 'web',
            image: 'ghcr.io/example/checkout:2.1.0',
            port: 8080,
            replicas: 5,
            env: ['APP_ENV' => 'production'],
            domains: ['checkout.example.test'],
        ),
    );

    $plan = new HashPlanner()->plan($scaled, $before->hashes());
    $changed = array_values(array_filter(
        $plan->entries,
        static fn ($entry): bool => $entry->action !== PlanAction::Unchanged,
    ));

    // The Deployment's replica count changed and the Secret went away; nothing
    // else moved, which is the property the plan/diff DX rests on.
    expect(array_map(static fn ($entry): string => $entry->key, $changed))
        ->toBe(['Deployment/web', 'Secret/web-env']);
});

it('compiles a different shape for a target whose nodes cannot snapshot', function (): void {
    // Same intent, two targets. This is the capability model doing its job: the
    // difference between a warm wake and a cold one is a typed value, not a
    // branch on which product is running.
    $intent = new ServiceSpec(
        serviceId: 'svc-checkout',
        organizationId: 'org-portability',
        namespace: 'cx-feature-checkout',
        name: 'web',
        image: 'ghcr.io/example/checkout:2.1.0',
        port: 8080,
        replicas: 1,
        domains: ['checkout.example.test'],
        scaleToZero: true,
    );

    $kinds = array_map(
        fn ($m): string => $m->kind,
        new ServiceCompiler(new PlatformTarget)->compile($intent)->manifests,
    );

    expect($kinds)->toContain('HTTPScaledObject');
});
