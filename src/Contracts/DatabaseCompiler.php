<?php

declare(strict_types=1);

namespace Cbox\Platform\Contracts;

use Cbox\Platform\Database\DatabaseSpec;
use Cbox\Platform\Manifest\ManifestSet;

/**
 * Database intent → CloudNativePG manifests. Pure and deterministic, like the
 * service compiler — golden-tested, byte-stable, drift-detectable.
 */
interface DatabaseCompiler
{
    public function compile(DatabaseSpec $spec): ManifestSet;
}
