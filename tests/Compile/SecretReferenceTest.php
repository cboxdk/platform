<?php

declare(strict_types=1);

use Cbox\Platform\Binding\BindingSpec;
use Cbox\Platform\Binding\ConnectionField;
use Cbox\Platform\Binding\ConnectionSource;
use Cbox\Platform\Manifest\Manifest;
use Cbox\Platform\Run\RunSpec;
use Cbox\Platform\Service\ProcessSpec;
use Cbox\Platform\Service\SecretReference;
use Cbox\Platform\Service\ServiceSpec;

/**
 * An environment variable from a Secret the OPERATOR created.
 *
 * The other half of secret env: `envSecret` carries values, so whoever builds the
 * spec holds the plaintext. A reference carries a name and a key, and the value
 * never passes through the spec, the compiler or anything it emits.
 */
function referencingSpec(array $references, array $extra = []): ServiceSpec
{
    return new ServiceSpec(...[
        'serviceId' => '01J0000000000000000000SVC1',
        'organizationId' => '01J0000000000000000000ORG1',
        'namespace' => 'cx-production-db9k2',
        'name' => 'web',
        'image' => 'acme/app:1.4.0',
        'port' => 80,
        'replicas' => 2,
        'env' => ['APP_ENV' => 'production'],
        'secretRefs' => $references,
        ...$extra,
    ]);
}

/** @return array<string, array<string, mixed>> */
function referencedEnvOf(?Manifest $manifest): array
{
    return collect($manifest->body['spec']['template']['spec']['containers'][0]['env'])->keyBy('name')->all();
}

it('compiles a reference to a secretKeyRef at the operator\'s Secret', function (): void {
    $set = test()->compileService(referencingSpec([
        new SecretReference('APP_KEY', 'cbox-id-app', 'APP_KEY'),
        new SecretReference('MAIL_PASSWORD', 'cbox-id-mail', 'password'),
    ]));

    $env = referencedEnvOf($set->find('Deployment/web'));

    expect($env['APP_KEY'])->toBe([
        'name' => 'APP_KEY',
        'valueFrom' => ['secretKeyRef' => ['name' => 'cbox-id-app', 'key' => 'APP_KEY']],
    ])
        ->and($env['MAIL_PASSWORD']['valueFrom']['secretKeyRef'])->toBe(['name' => 'cbox-id-mail', 'key' => 'password'])
        // Never optional. A missing Secret holds the pod in
        // CreateContainerConfigError, which is the honest failure — a pod that
        // booted without its APP_KEY would be serving, and wrong.
        ->and($env['APP_KEY']['valueFrom']['secretKeyRef'])->not->toHaveKey('optional');

    // The operator's Secret is THEIRS: nothing here creates, overwrites or
    // prunes it.
    expect(collect($set->manifests)->where('kind', 'Secret')->pluck('name')->all())->toBe([]);
});

it('gives every process and every run the same references as the web process', function (): void {
    $spec = referencingSpec(
        [new SecretReference('APP_KEY', 'cbox-id-app', 'APP_KEY')],
        ['processes' => [
            new ProcessSpec('queue', ['php', 'artisan', 'queue:work'], 1),
            new ProcessSpec('scheduler', ['php', 'artisan', 'schedule:work'], 1),
        ]],
    );

    $set = test()->compileService($spec);
    $expected = ['secretKeyRef' => ['name' => 'cbox-id-app', 'key' => 'APP_KEY']];

    foreach (['web', 'web-queue', 'web-scheduler'] as $deployment) {
        expect(referencedEnvOf($set->find('Deployment/'.$deployment))['APP_KEY']['valueFrom'])->toBe($expected);
    }

    $run = test()->compileRun(new RunSpec('run-1', 'web-run-1', ['php', 'artisan', 'tinker'], $spec));

    expect(referencedEnvOf($run->manifests[0])['APP_KEY']['valueFrom'])->toBe($expected);
});

it('refuses a variable claimed by a reference and by anything else', function (array $extra): void {
    expect(fn () => test()->compileService(referencingSpec(
        [new SecretReference('APP_KEY', 'cbox-id-app', 'APP_KEY')],
        $extra,
    )))->toThrow(LogicException::class, 'declared more than once');
})->with([
    'a plain value' => [['env' => ['APP_KEY' => 'base64:inline']]],
    'a compiled secret' => [['envSecret' => ['APP_KEY' => 'base64:inline']]],
    'a binding' => [['bindings' => [new BindingSpec(
        databaseName: 'db',
        engine: 'postgres',
        map: [['field' => ConnectionField::Password, 'name' => 'APP_KEY']],
        source: new ConnectionSource('db-app', ['password' => 'password'], []),
    )]]],
    'another reference' => [['secretRefs' => [
        new SecretReference('APP_KEY', 'cbox-id-app', 'APP_KEY'),
        new SecretReference('APP_KEY', 'cbox-id-other', 'APP_KEY'),
    ]]],
]);

it('refuses to reference a Secret this platform writes itself', function (string $secret): void {
    expect(fn () => test()->compileService(referencingSpec([new SecretReference('X', $secret, 'X')])))
        ->toThrow(LogicException::class, 'a Secret this platform writes');
})->with(['web-env', 'web-registry']);

it('refuses names Kubernetes would refuse, before anything is compiled', function (string $variable, string $secret, string $key, string $message): void {
    expect(fn () => new SecretReference($variable, $secret, $key))->toThrow(LogicException::class, $message);
})->with([
    'variable with a hyphen' => ['APP-KEY', 'app', 'k', 'environment variable name'],
    'variable starting with a digit' => ['1KEY', 'app', 'k', 'environment variable name'],
    'empty variable' => ['', 'app', 'k', 'environment variable name'],
    'upper-case secret' => ['K', 'App', 'k', 'Secret name'],
    'secret ending in a hyphen' => ['K', 'app-', 'k', 'Secret name'],
    'secret too long' => ['K', str_repeat('a', 254), 'k', 'Secret name'],
    'key with a slash' => ['K', 'app', 'a/b', 'key in a Secret'],
    'key that is a dot' => ['K', 'app', '.', 'key in a Secret'],
    'key starting with two dots' => ['K', 'app', '..x', 'key in a Secret'],
]);

it('accepts the names Kubernetes accepts', function (): void {
    $reference = new SecretReference('_lower_ok_1', 'cbox-id.app-2', 'tls.crt_KEY-1');

    expect($reference->toEnv()['valueFrom']['secretKeyRef'])->toBe(['name' => 'cbox-id.app-2', 'key' => 'tls.crt_KEY-1']);
});
