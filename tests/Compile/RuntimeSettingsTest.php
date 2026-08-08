<?php

declare(strict_types=1);

use Cbox\Platform\Service\FpmProfile;
use Cbox\Platform\Service\OpcacheJit;
use Cbox\Platform\Service\RuntimeSettings;
use Cbox\Platform\Service\ServiceSpec;

/*
 * What a service asks of the image it runs on.
 *
 * All of this was already reachable — by a customer who had read the base
 * image's documentation and typed variable names into an environment table.
 * The point of modelling it is the rules a key/value table cannot express.
 */

function runtimeSpec(RuntimeSettings $runtime, string $baseImage = 'ghcr.io/cboxdk/php-baseimages/php-fpm-nginx:8.5-bookworm', string $podCidr = ''): ServiceSpec
{
    return new ServiceSpec(
        serviceId: '01J0000000000000000000SVC1',
        organizationId: '01J0000000000000000000ORG1',
        name: 'web',
        namespace: 'cx-prod-abc',
        image: 'registry.test/builds/org/web:1',
        port: 8080,
        replicas: 1,
        baseImage: $baseImage,
        runtime: $runtime,
        podCidr: $podCidr,
    );
}

/**
 * @return array<string, string>
 */
function runtimeContainerEnv(ServiceSpec $spec): array
{
    $deployment = collect(test()->compileService($spec)->manifests)
        ->firstWhere('kind', 'Deployment');

    $env = [];

    foreach ($deployment->body['spec']['template']['spec']['containers'][0]['env'] ?? [] as $entry) {
        if (isset($entry['value'])) {
            $env[$entry['name']] = $entry['value'];
        }
    }

    return $env;
}

it('emits nothing for a service that has chosen nothing', function (): void {
    $env = runtimeContainerEnv(runtimeSpec(new RuntimeSettings));

    // THIS IS WHAT MAKES IT SAFE TO ADD to services that already exist. An
    // absent setting means the base image's own default applies, so the
    // compiled output does not move until somebody chooses something.
    expect($env)->not->toHaveKey('LARAVEL_SCHEDULER')
        ->and($env)->not->toHaveKey('PHP_MEMORY_LIMIT')
        ->and($env)->not->toHaveKey('PHP_FPM_AUTOTUNE_PROFILE');
});

it('turns on the processes the base image supervises', function (): void {
    $env = runtimeContainerEnv(runtimeSpec(new RuntimeSettings(
        scheduler: true,
        horizon: true,
        queue: true,
        queueScale: 4,
    )));

    expect($env)->toMatchArray([
        'LARAVEL_SCHEDULER' => 'true',
        'LARAVEL_HORIZON' => 'true',
        'LARAVEL_QUEUE' => 'true',
        'CBOX_INIT_PROCESS_QUEUE_DEFAULT_SCALE' => '4',
    ]);
});

it('does not size a queue nobody runs', function (): void {
    $env = runtimeContainerEnv(runtimeSpec(new RuntimeSettings(queueScale: 8)));

    // A setting that does nothing and reads as if it does. The customer sees 8
    // workers configured and has none.
    expect($env)->not->toHaveKey('CBOX_INIT_PROCESS_QUEUE_DEFAULT_SCALE');
});

it('compiles the PHP and OPcache tuning that was chosen', function (): void {
    $env = runtimeContainerEnv(runtimeSpec(new RuntimeSettings(
        memoryLimit: '512M',
        maxExecutionTime: 120,
        opcacheJit: OpcacheJit::Off,
        fpmProfile: FpmProfile::Heavy,
    )));

    expect($env)->toMatchArray([
        'PHP_MEMORY_LIMIT' => '512M',
        'PHP_MAX_EXECUTION_TIME' => '120',
        'PHP_OPCACHE_JIT' => 'off',
        'PHP_FPM_AUTOTUNE_PROFILE' => 'heavy',
    ]);
});

it('lets a variable set by hand win over the form', function (): void {
    $spec = new ServiceSpec(
        serviceId: '01J0000000000000000000SVC1',
        organizationId: '01J0000000000000000000ORG1',
        name: 'web',
        namespace: 'cx-prod-abc',
        image: 'registry.test/builds/org/web:1',
        port: 8080,
        replicas: 1,
        env: ['PHP_MEMORY_LIMIT' => '1G'],
        baseImage: 'ghcr.io/cboxdk/php-baseimages/php-fpm-nginx:8.5-bookworm',
        runtime: new RuntimeSettings(memoryLimit: '256M'),
    );

    // The escape hatch has to stay open. A customer who set something by hand
    // before this existed must not have it silently replaced by a form default
    // they never touched.
    expect(runtimeContainerEnv($spec)['PHP_MEMORY_LIMIT'])->toBe('1G');
});

it('compiles none of it onto an image that cannot act on it', function (): void {
    $env = runtimeContainerEnv(runtimeSpec(new RuntimeSettings(scheduler: true, memoryLimit: '512M'), baseImage: ''));

    // A Dockerfile build or a prebuilt image has no cbox-init in it. Setting
    // LARAVEL_SCHEDULER there is a switch that reports itself on and does
    // nothing — this platform's most-repeated failure.
    expect($env)->not->toHaveKey('LARAVEL_SCHEDULER')
        ->and($env)->not->toHaveKey('PHP_MEMORY_LIMIT');
});

it('tells nginx which addresses may speak for a client', function (): void {
    $env = runtimeContainerEnv(runtimeSpec(new RuntimeSettings, podCidr: '10.244.0.0/16'));

    // The last hop. Envoy receives the real client address over PROXY protocol
    // and forwards it in X-Forwarded-For — and the application still saw
    // Envoy's pod IP in REMOTE_ADDR, because nginx trusts nobody by default.
    // Every layer correct, and the answer still wrong at the only place a
    // customer's code can read it.
    expect($env['NGINX_TRUSTED_PROXIES'])->toBe('10.244.0.0/16');
});

it('trusts nothing when the pod range is unknown', function (): void {
    $env = runtimeContainerEnv(runtimeSpec(new RuntimeSettings));

    // Trusting a guessed range is worse than trusting none: it accepts a
    // forwarded address from somewhere nobody verified.
    expect($env)->not->toHaveKey('NGINX_TRUSTED_PROXIES');
});

it('knows when a background process keeps the container awake', function (): void {
    // A scheduler ticks every minute and a queue worker blocks on a connection.
    // Either means the container is never idle, so it never scales to zero —
    // and the customer is billed for a service they were told would sleep.
    expect((new RuntimeSettings(scheduler: true))->keepsContainerAwake())->toBeTrue()
        ->and((new RuntimeSettings(queue: true))->keepsContainerAwake())->toBeTrue()
        ->and((new RuntimeSettings(optimize: true))->keepsContainerAwake())->toBeFalse()
        ->and((new RuntimeSettings)->keepsContainerAwake())->toBeFalse();
});

it('reads back what was stored', function (): void {
    $settings = RuntimeSettings::fromArray([
        'scheduler' => true,
        'queue' => true,
        'queue_scale' => '3',
        'opcache_jit' => 'function',
        'fpm_profile' => 'bursty',
        'memory_limit' => '  384M  ',
        'timezone' => '',
    ]);

    expect($settings->scheduler)->toBeTrue()
        ->and($settings->queueScale)->toBe(3)
        ->and($settings->opcacheJit)->toBe(OpcacheJit::Function)
        ->and($settings->fpmProfile)->toBe(FpmProfile::Bursty)
        // Trimmed, because a trailing space in a memory limit is a PHP startup
        // warning nobody reads.
        ->and($settings->memoryLimit)->toBe('384M')
        // Empty is not a choice.
        ->and($settings->timezone)->toBeNull();
});
