<?php

declare(strict_types=1);

namespace Cbox\Platform\Contracts;

use Cbox\Platform\Manifest\ManifestSet;
use Cbox\Platform\Route\EnvironmentGatewaySpec;

/**
 * Compiles an environment's ingress — the hostnames it terminates and the
 * certificates that let it.
 */
interface GatewayCompiler
{
    public function compile(EnvironmentGatewaySpec $spec): ManifestSet;
}
