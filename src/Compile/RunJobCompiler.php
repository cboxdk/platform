<?php

declare(strict_types=1);

namespace Cbox\Platform\Compile;

use Cbox\Platform\Contracts\Compiler;
use Cbox\Platform\Contracts\RunCompiler;
use Cbox\Platform\Manifest\Manifest;
use Cbox\Platform\Manifest\ManifestSet;
use Cbox\Platform\Run\RunSpec;
use RuntimeException;

/**
 * Compiles a one-off run to a Kubernetes Job.
 *
 * The container is taken from the service's OWN compiled Deployment rather
 * than rebuilt here, and that is the whole design. A run has to see exactly
 * what the service sees — the same image, the same env, the same database
 * bindings, the same pull credential — and any second implementation of "what
 * the service sees" drifts from the first. Reading it out of the compiled set
 * makes the guarantee structural: there is only one place that decides, and a
 * run cannot disagree with the Deployment because it IS the Deployment's
 * container with a command on it.
 *
 * `php artisan migrate` is the case this exists for. Before it, an application
 * with a database could not be initialised at all.
 */
class RunJobCompiler implements RunCompiler
{
    public const MANAGED_LABEL = 'cortex.io/managed';

    public function __construct(
        private readonly Compiler $services,
    ) {}

    public function compile(RunSpec $spec): ManifestSet
    {
        $compiled = $this->services->compile($spec->service);

        $deployment = null;

        foreach ($compiled->manifests as $manifest) {
            if ($manifest->kind === 'Deployment') {
                $deployment = $manifest;
                break;
            }
        }

        if ($deployment === null) {
            throw new RuntimeException("Service [{$spec->service->name}] compiled no Deployment to run from.");
        }

        $podSpec = $deployment->map('spec', 'template', 'spec');
        $container = $deployment->listItem(0, 'spec', 'template', 'spec', 'containers');

        $container['name'] = 'run';
        $container['command'] = $spec->command;

        // A run does not serve. Ports on a Job are inert at best, and a
        // readiness probe on a container that exits is a contradiction.
        unset($container['ports'], $container['readinessProbe'], $container['livenessProbe']);

        $podSpec['containers'] = [$container];
        $podSpec['restartPolicy'] = 'Never';

        // The pod carries the same Secrets the Deployment does — env and pull
        // credential — so the run's manifests include them. They are already
        // in the compiled set; emitting them again here would fight the
        // service's own reconciler over the same objects.
        $manifests = [$this->job($spec, $podSpec)];

        return new ManifestSet($manifests);
    }

    /**
     * @param  array<array-key, mixed>  $podSpec
     */
    private function job(RunSpec $spec, array $podSpec): Manifest
    {
        $labels = [
            self::MANAGED_LABEL => 'true',
            'cortex.io/organization' => $spec->service->organizationId,
            'cortex.io/service' => $spec->service->serviceId,
            'cortex.io/run' => $spec->runId,
            'app.kubernetes.io/managed-by' => 'cortex-sync',
        ];

        return new Manifest(
            apiVersion: 'batch/v1',
            kind: 'Job',
            name: $spec->jobName,
            namespace: $spec->service->namespace,
            body: [
                'apiVersion' => 'batch/v1',
                'kind' => 'Job',
                'metadata' => [
                    'name' => $spec->jobName,
                    'namespace' => $spec->service->namespace,
                    'labels' => $labels,
                ],
                'spec' => [
                    // No retries. A migration that failed halfway is not
                    // improved by being run again unattended; the customer
                    // decides, having read why.
                    'backoffLimit' => 0,
                    'activeDeadlineSeconds' => 3600,
                    // Kept after completion so the logs survive long enough to
                    // be read — a run whose output vanishes on success is a
                    // run that cannot be trusted on failure either.
                    'ttlSecondsAfterFinished' => 86400,
                    'template' => [
                        'metadata' => ['labels' => $labels],
                        'spec' => $podSpec,
                    ],
                ],
            ],
        );
    }
}
