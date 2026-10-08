<?php

declare(strict_types=1);

namespace Cbox\Platform\Compile;

use Cbox\Platform\Capability\PlatformTarget;
use Cbox\Platform\Contracts\Compiler;
use Cbox\Platform\Contracts\ReleaseCompiler;
use Cbox\Platform\Manifest\Manifest;
use Cbox\Platform\Manifest\ManifestSet;
use Cbox\Platform\Run\RunSpec;
use Cbox\Platform\Service\ServiceSpec;

/**
 * A service's release command — `php artisan migrate --force` — as the Job an
 * applier runs to completion BEFORE the workloads roll.
 *
 * A RUN, WITH A NAME THAT IS THE RELEASE. It is built by {@see RunJobCompiler},
 * so it is the web Deployment's own container with the command on it: the same
 * image, env, Secret references, bindings and pull credential, and no way to
 * drift from what the new pods will see. What this adds is the name.
 *
 * NAMED FOR WHAT IT RUNS. `<service>-release-<hash>`, where the hash is of the
 * Job's pod template — image, command, environment, everything. So:
 *
 *   - the same release deployed twice is the SAME Job, and an applier finds it
 *     already complete instead of migrating twice;
 *   - a new image, a changed variable or a different command is a NEW Job.
 *     A Job's pod template is immutable once created, so re-applying one name
 *     with a new image is refused by the API server — a fixed name could only
 *     ever run the first release;
 *   - it is a pure function of the spec, like everything else here: no clock,
 *     no counter, no release number the compiler would have to be told.
 *
 * NOT PART OF THE SERVICE'S MANIFEST SET, and that is the point rather than an
 * omission. A set is applied at once and has no order; this has to finish, and
 * succeed, before the Deployments in that set are allowed to change. Keeping it
 * apart is what lets an applier sequence it:
 *
 *     1. apply the service's set WITHOUT its Deployments and scalers
 *        (the namespace, Secrets, Services, databases — what the release needs)
 *     2. apply this Job and wait for `Complete` — or `Failed`, and stop there,
 *        with the running release untouched
 *     3. apply the full set, and the workloads roll
 *
 * NOTHING WHILE SUSPENDED. A service that is put away runs nothing, and a
 * migration nobody is going to serve is a migration nobody is watching.
 */
class ReleaseJobCompiler implements ReleaseCompiler
{
    /** The longest a Job name can be and still label its pods (`job-name`). */
    private const MAX_NAME = 63;

    public function __construct(
        private readonly Compiler $services,
        private readonly PlatformTarget $target = new PlatformTarget,
    ) {}

    public function compile(ServiceSpec $spec): ManifestSet
    {
        if ($spec->release === [] || $spec->suspended) {
            return new ManifestSet([]);
        }

        $job = new RunJobCompiler($this->services, $this->target)->compile(new RunSpec(
            runId: 'release',
            jobName: $spec->name.'-release',
            command: $spec->release,
            service: $spec,
        ))->manifests[0];

        // The template's canonical hash — the same canonicalisation plan/diff
        // uses, so two specs that compile to the same pod are the same release.
        /** @var array<string, mixed> $template */
        $template = $job->map('spec', 'template');
        $name = self::jobName($spec->name, new Manifest('batch/v1', 'Job', '', '', $template)->hash());

        $body = $job->body;
        $body['metadata'] = ['name' => $name] + $job->map('metadata');

        return new ManifestSet([new Manifest(
            apiVersion: $job->apiVersion,
            kind: $job->kind,
            name: $name,
            namespace: $job->namespace,
            body: $body,
        )]);
    }

    /**
     * `<service>-release-<10 hex>`, shortened from the service's end so the
     * whole thing stays a valid pod label.
     */
    public static function jobName(string $service, string $hash): string
    {
        $suffix = '-release-'.substr($hash, 0, 10);

        return rtrim(substr($service, 0, self::MAX_NAME - strlen($suffix)), '-').$suffix;
    }
}
