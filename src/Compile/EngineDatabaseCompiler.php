<?php

declare(strict_types=1);

namespace Cbox\Platform\Compile;

use Cbox\Platform\Contracts\DatabaseCompiler;
use Cbox\Platform\Database\DatabaseEngine;
use Cbox\Platform\Database\DatabaseSpec;
use Cbox\Platform\Manifest\ManifestSet;

/**
 * Routes a database to the compiler for its engine. The split is not cosmetic:
 * Postgres is handed to an operator that owns its own pod spec, while Valkey and
 * Percona are scheduled by Cortex — which is what decides whether the warm-restore
 * tier is available at all.
 *
 * Deny-by-default: an engine with no registered compiler is refused rather than
 * silently compiled as something else.
 */
class EngineDatabaseCompiler implements DatabaseCompiler
{
    public function __construct(
        private readonly CnpgDatabaseCompiler $postgres,
        private readonly StatefulDatabaseCompiler $stateful,
    ) {}

    public function compile(DatabaseSpec $spec): ManifestSet
    {
        return match ($spec->engine) {
            DatabaseEngine::Postgres => $this->postgres->compile($spec),
            DatabaseEngine::Valkey, DatabaseEngine::Percona => $this->stateful->compile($spec),
        };
    }
}
