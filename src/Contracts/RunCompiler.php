<?php

declare(strict_types=1);

namespace Cbox\Platform\Contracts;

use Cbox\Platform\Manifest\ManifestSet;
use Cbox\Platform\Run\RunSpec;

/**
 * Compiles a one-off command in a service's image to something the cluster can
 * run once.
 */
interface RunCompiler
{
    public function compile(RunSpec $spec): ManifestSet;
}
