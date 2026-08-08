<?php

declare(strict_types=1);

/**
 * The rules that make this package shared infrastructure rather than one
 * product's internals with a second name.
 *
 * They are asserted here because they were previously only documented, and a
 * documented invariant is one a future `composer require` can break in silence.
 */
arch('the package knows nothing about its consumers')
    ->expect('Cbox\Platform')
    ->not->toUse([
        'App',                  // Cbox Cortex
        'Illuminate\Database',  // any ORM-backed consumer
        'Illuminate\Support\Facades',
        'Illuminate\Http',
        'Illuminate\Foundation',
    ]);

arch('nothing is sealed against a consumer that needs to extend it')
    ->expect('Cbox\Platform')
    ->classes()
    ->not->toBeFinal();

arch('value objects are immutable')
    ->expect([
        'Cbox\Platform\Service',
        'Cbox\Platform\Database',
        'Cbox\Platform\Binding',
        'Cbox\Platform\Route',
        'Cbox\Platform\Run',
        'Cbox\Platform\Manifest',
        'Cbox\Platform\Capability',
    ])
    ->classes()
    ->toBeReadonly();

arch('contracts are interfaces')
    ->expect('Cbox\Platform\Contracts')
    ->toBeInterfaces();

arch('strict types everywhere')
    ->expect('Cbox\Platform')
    ->toUseStrictTypes();

arch('no leftover debugging')
    ->expect(['dd', 'dump', 'var_dump', 'ray', 'print_r', 'error_log'])
    ->not->toBeUsed();

/**
 * THE PURITY GATE, and it is deliberately a text scan rather than an arch rule.
 *
 * Arch rules see imports and calls to named symbols. The impurity this package
 * was extracted out of was none of those: it was a static call one level down
 * into a value object, which read `config()` there. Nothing in the compiler
 * files referenced configuration, and the audit that only looked at them
 * reported the compilers pure. They were not.
 *
 * So this scans every line of source for the forbidden call, wherever it sits.
 * A grep is a blunt instrument; blunt is the point.
 */
it('performs no IO anywhere in the package', function (): void {
    $forbidden = [
        // configuration and environment
        'config(', 'env(', 'getenv(',
        // the filesystem
        'file_get_contents(', 'file_put_contents(', 'fopen(', 'glob(',
        'scandir(', 'base_path(', 'storage_path(', 'resource_path(',
        'is_file(', 'file_exists(',
        // the network
        'curl_init(', 'fsockopen(', 'stream_socket_client(',
        // the clock and the dice — they break determinism, which is the
        // property plan/diff, golden tests and drift detection all rest on
        'time(', 'microtime(', 'date(', 'mt_rand(', 'random_int(',
        'random_bytes(', 'uniqid(', 'now(',
        // the container
        'app(', 'resolve(', 'dispatch(', 'event(',
    ];

    $offences = [];

    foreach (sourceFiles() as $path => $source) {
        foreach (explode("\n", $source) as $number => $line) {
            $code = $line;

            // Comments describe the forbidden thing constantly; only code counts.
            if (preg_match('/^\s*(\*|\/\/|\/\*)/', $code) === 1) {
                continue;
            }

            foreach ($forbidden as $call) {
                $name = rtrim($call, '(');

                // A method DECLARATION may be named anything; `private function
                // env(DatabaseSpec $spec)` builds a container's environment and
                // reads nothing. Stripped rather than skipped by line, because
                // a single-line method body would otherwise take its own call
                // out of scope with it — which is exactly what this check did
                // when it was first written, and the tripwire caught it.
                $scanned = preg_replace('/\bfunction\s+'.preg_quote($name, '/').'\s*\(/', '', $code) ?? $code;

                // Not a method call on something — `$this->config(` is fine,
                // and so is a function whose name merely ends in one of these.
                if (preg_match('/(?<![\w>$\\\\-])'.preg_quote($call, '/').'/', $scanned) === 1) {
                    $offences[] = $path.':'.($number + 1).' — '.trim($code);
                }
            }
        }
    }

    expect($offences)->toBe([]);
});

it('declares only what it truly depends on', function (): void {
    $composer = json_decode(
        (string) file_get_contents(__DIR__.'/../composer.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    // A runtime dependency here is one every consumer inherits, so the list is
    // short on purpose and any addition is a decision, not a convenience.
    expect(array_keys($composer['require']))->toBe(['php', 'symfony/yaml']);
});

/**
 * @return array<string, string>
 */
function sourceFiles(): array
{
    $files = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__.'/../src', FilesystemIterator::SKIP_DOTS),
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $files['src/'.$file->getFilename()] = (string) file_get_contents($file->getPathname());
    }

    ksort($files);

    return $files;
}
