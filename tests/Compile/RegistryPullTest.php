<?php

declare(strict_types=1);

use Cbox\Platform\Service\RegistrySpec;
use Cbox\Platform\Service\ServiceSpec;

function privateImageSpec(?RegistrySpec $registry): ServiceSpec
{
    return new ServiceSpec(
        serviceId: '01J0000000000000000000SVC1',
        organizationId: 'org-r',
        namespace: 'cx-prod-aaaaaa',
        name: 'web',
        image: 'registry.example.com/acme/app:1.4.0',
        port: 80,
        replicas: 1,
        registry: $registry,
    );
}

it('gives the kubelet a credential for a private image', function (): void {
    $set = test()->compileService(privateImageSpec(new RegistrySpec(
        server: 'https://registry.example.com',
        username: 'acme-ci',
        password: 'pull-token-FIXTURE',
    )));

    $pull = collect($set->manifests)->first(
        fn ($m): bool => $m->kind === 'Secret' && $m->name === 'web-registry',
    );

    // Without this the pod sits in ImagePullBackOff with an error that says
    // nothing about Cortex — and most customers with a build pipeline have
    // private images, so this was not an edge case.
    expect($pull)->not->toBeNull()
        ->and($pull->body['type'])->toBe('kubernetes.io/dockerconfigjson');

    /** @var array<string, mixed> $config */
    $config = json_decode($pull->body['stringData']['.dockerconfigjson'], true, 512, JSON_THROW_ON_ERROR);

    expect($config['auths']['https://registry.example.com']['username'])->toBe('acme-ci')
        ->and($config['auths']['https://registry.example.com']['auth'])
        ->toBe(base64_encode('acme-ci:pull-token-FIXTURE'));

    /** @var array<string, mixed> $pod */
    $pod = collect($set->manifests)->firstWhere('kind', 'Deployment')
        ->body['spec']['template']['spec'];

    expect($pod['imagePullSecrets'])->toBe([['name' => 'web-registry']]);

    // The Secret comes first: a pod scheduled ahead of its pull credential
    // fails the pull once and backs off before retrying.
    $kinds = array_map(fn ($m): string => $m->kind, $set->manifests);
    expect(array_search('Secret', $kinds, true))->toBeLessThan(array_search('Deployment', $kinds, true));
});

it('asks for no credential when the image is public', function (): void {
    $set = test()->compileService(privateImageSpec(null));

    /** @var array<string, mixed> $pod */
    $pod = collect($set->manifests)->firstWhere('kind', 'Deployment')
        ->body['spec']['template']['spec'];

    // Public images are the common case and must not be made to depend on a
    // credential nobody needs.
    expect($pod)->not->toHaveKey('imagePullSecrets')
        ->and(collect($set->manifests)->contains(fn ($m): bool => $m->name === 'web-registry'))->toBeFalse();
});
