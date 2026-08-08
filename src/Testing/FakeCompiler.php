<?php

declare(strict_types=1);

namespace Cbox\Platform\Testing;

use Cbox\Platform\Contracts\Compiler;
use Cbox\Platform\Manifest\Manifest;
use Cbox\Platform\Manifest\ManifestSet;
use Cbox\Platform\Service\ServiceSpec;

/**
 * A compiler for testing the layer ABOVE the compiler.
 *
 * A consumer's apply path, deploy orchestration and status handling all take a
 * `ManifestSet` and should be testable without asserting on real compiled
 * Kubernetes objects — those already have golden tests here, and a second copy
 * of them in a consumer's suite breaks for reasons that have nothing to do with
 * the consumer.
 *
 * Records what it was asked to compile, so "did the deploy path build the spec
 * I expected" is a question the mapper's caller can actually answer.
 */
class FakeCompiler implements Compiler
{
    /** @var list<ServiceSpec> */
    public array $compiled = [];

    public function __construct(private ?ManifestSet $returns = null) {}

    /** Return this set for every compile, instead of the placeholder. */
    public function returning(ManifestSet $set): self
    {
        $this->returns = $set;

        return $this;
    }

    public function compile(ServiceSpec $spec): ManifestSet
    {
        $this->compiled[] = $spec;

        return $this->returns ?? new ManifestSet([
            new Manifest(
                apiVersion: 'apps/v1',
                kind: 'Deployment',
                name: $spec->name,
                namespace: $spec->namespace,
                body: [
                    'apiVersion' => 'apps/v1',
                    'kind' => 'Deployment',
                    'metadata' => ['name' => $spec->name, 'namespace' => $spec->namespace],
                    'spec' => ['replicas' => $spec->replicas],
                ],
            ),
        ]);
    }

    public function lastSpec(): ?ServiceSpec
    {
        return $this->compiled === [] ? null : $this->compiled[count($this->compiled) - 1];
    }
}
