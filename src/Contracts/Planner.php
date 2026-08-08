<?php

declare(strict_types=1);

namespace Cbox\Platform\Contracts;

use Cbox\Platform\Manifest\ManifestSet;
use Cbox\Platform\Plan\Plan;

interface Planner
{
    /**
     * Diff freshly compiled manifests against the hashes recorded when the
     * previous plan was applied.
     *
     * @param  array<string, string>  $appliedHashes  key => content hash
     */
    public function plan(ManifestSet $desired, array $appliedHashes): Plan;
}
