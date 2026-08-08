<?php

declare(strict_types=1);

namespace Cbox\Platform\Contracts;

use Cbox\Platform\Manifest\ManifestSet;
use Cbox\Platform\Service\ServiceSpec;

/**
 * Intent → Kubernetes manifests. Pure and deterministic: same spec, same
 * output, byte for byte — that property is what makes plan/diff, golden
 * tests, and drift detection trivial. No IO, no clock, no randomness.
 */
interface Compiler
{
    public function compile(ServiceSpec $spec): ManifestSet;
}
