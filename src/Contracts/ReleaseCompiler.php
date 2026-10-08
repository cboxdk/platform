<?php

declare(strict_types=1);

namespace Cbox\Platform\Contracts;

use Cbox\Platform\Manifest\ManifestSet;
use Cbox\Platform\Service\ServiceSpec;

/**
 * Compiles a service's release command to something the cluster runs once,
 * to completion, before the service's workloads change.
 *
 * An empty set means there is nothing to run first.
 */
interface ReleaseCompiler
{
    public function compile(ServiceSpec $spec): ManifestSet;
}
