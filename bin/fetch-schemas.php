#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Cache the CRD schemas this package compiles against, from a real cluster.
 *
 * WHY A FIXTURE AND NOT A CATALOGUE. Validating against a public schema
 * catalogue answers "is this valid for SOME version of that CRD". It does not
 * answer the question that matters — "is this valid for the version WE run" —
 * and it puts a network fetch inside a gate.
 *
 * A cached fixture answers the real question, needs no network in CI, and makes
 * an add-on upgrade show up the way everything else here does: as a diff you
 * have to read. Re-run this after upgrading KEDA or the gateway and the schema
 * changes land in git next to the golden files they might invalidate.
 *
 * Usage:  php bin/fetch-schemas.php <kube-context> [<kube-context> ...]
 *
 * Read-only: it lists CustomResourceDefinitions and writes nothing to any
 * cluster.
 */
/**
 * Exactly the kinds this package emits — not the groups they live in.
 *
 * CloudNativePG alone ships eleven CRDs and its Cluster schema is most of a
 * megabyte; caching the ten we compile against instead of everything in seven
 * groups is the difference between a fixture somebody reads in a diff and one
 * they scroll past. If this list and the compilers disagree, `ApiVersionTest`
 * is the one that notices.
 */
const KINDS = [
    'keda.sh' => ['ScaledObject'],
    'http.keda.sh' => ['HTTPScaledObject'],
    'gateway.envoyproxy.io' => ['ClientTrafficPolicy'],
    'gateway.networking.k8s.io' => ['Gateway', 'HTTPRoute'],
    'cert-manager.io' => ['Issuer', 'Certificate'],
    'postgresql.cnpg.io' => ['Cluster', 'ScheduledBackup'],
    'snapshot.storage.k8s.io' => ['VolumeSnapshot'],
];

$contexts = array_slice($argv, 1);

if ($contexts === []) {
    fwrite(STDERR, "usage: php bin/fetch-schemas.php <kube-context> [...]\n");
    exit(2);
}

$out = dirname(__DIR__).'/tests/Schemas';
$written = [];
$seen = [];

foreach ($contexts as $context) {
    $json = shell_exec(sprintf(
        'kubectl --context %s --request-timeout=30s get crd -o json 2>/dev/null',
        escapeshellarg($context),
    ));

    if (! is_string($json) || trim($json) === '') {
        fwrite(STDERR, "unreachable: {$context}\n");

        continue;
    }

    $crds = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    foreach ($crds['items'] ?? [] as $crd) {
        $group = $crd['spec']['group'] ?? '';

        if (! array_key_exists($group, KINDS)) {
            continue;
        }

        $kind = $crd['spec']['names']['kind'] ?? '';

        if (! in_array($kind, KINDS[$group], true)) {
            continue;
        }

        foreach ($crd['spec']['versions'] ?? [] as $version) {
            $schema = $version['schema']['openAPIV3Schema'] ?? null;
            $name = $version['name'] ?? '';

            if ($schema === null || $kind === '' || $name === '') {
                continue;
            }

            // kubeconform's layout: {group}/{kind}_{version}.json, lower-cased.
            $path = $out.'/'.$group;
            $file = $path.'/'.strtolower($kind).'_'.strtolower($name).'.json';

            if (isset($seen[$file])) {
                continue;   // the first cluster that has it wins; they agree or you have a problem
            }

            @mkdir($path, 0o755, true);

            // Pretty-printed and key-sorted, so re-running produces a readable
            // diff rather than one big line that changed.
            ksortRecursive($schema);

            file_put_contents(
                $file,
                json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
            );

            $seen[$file] = true;
            $written[] = $group.'/'.$kind.' '.$name.'  ('.$context.')';
        }
    }
}

sort($written);

foreach ($written as $line) {
    echo '  ', $line, "\n";
}

echo count($written), " schemas cached in tests/Schemas\n";

$missing = [];

foreach (KINDS as $group => $kinds) {
    foreach ($kinds as $kind) {
        if (glob($out.'/'.$group.'/'.strtolower($kind).'_*.json') === []) {
            $missing[] = $group.'/'.$kind;
        }
    }
}

if ($missing !== []) {
    // Named, not silently absent. A schema this package needs and no reachable
    // cluster has is exactly the gap worth knowing about.
    fwrite(STDERR, "\nNOT FOUND on any given cluster:\n  ".implode("\n  ", $missing)."\n");
}

/**
 * @param  array<array-key, mixed>  $value
 */
function ksortRecursive(array &$value): void
{
    if (! array_is_list($value)) {
        ksort($value);
    }

    foreach ($value as &$item) {
        if (is_array($item)) {
            ksortRecursive($item);
        }
    }
}
