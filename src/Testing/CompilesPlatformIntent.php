<?php

declare(strict_types=1);

namespace Cbox\Platform\Testing;

use Cbox\Platform\Capability\PlatformTarget;
use Cbox\Platform\Compile\BackupCompiler;
use Cbox\Platform\Compile\CnpgDatabaseCompiler;
use Cbox\Platform\Compile\EngineDatabaseCompiler;
use Cbox\Platform\Compile\EnvironmentGatewayCompiler;
use Cbox\Platform\Compile\RunJobCompiler;
use Cbox\Platform\Compile\ServiceCompiler;
use Cbox\Platform\Compile\StatefulDatabaseCompiler;
use Cbox\Platform\Contracts\SnapshotRuntime;
use Cbox\Platform\Database\BackupSpec;
use Cbox\Platform\Database\DatabaseSpec;
use Cbox\Platform\Manifest\ManifestSet;
use Cbox\Platform\Route\EnvironmentGatewaySpec;
use Cbox\Platform\Run\RunSpec;
use Cbox\Platform\Runtime\NoSnapshotRuntime;
use Cbox\Platform\Service\ServiceSpec;

/**
 * Wiring the compilers by hand, for tests and for anyone embedding the package
 * without a container.
 *
 * The package's own suite uses this — including its golden tests — which is
 * what keeps the README's claim honest: if compiling an environment needed a
 * framework booted around it, this trait could not exist.
 *
 * The target is held rather than passed because a test usually declares the
 * cluster's capabilities once and then compiles several things against them.
 * {@see self::compilingFor()} replaces it; everything below reads it.
 */
trait CompilesPlatformIntent
{
    private ?PlatformTarget $platformTarget = null;

    /** Compile everything after this against the given target. */
    protected function compilingFor(PlatformTarget $target): void
    {
        $this->platformTarget = $target;
    }

    /**
     * Compile against a cluster whose nodes can checkpoint an idle workload.
     *
     * Null is the other tier and not a lesser one: with no snapshot runtime a
     * wake is a real pod start, which compiles to a different shape rather than
     * a degraded one.
     */
    protected function compilingWithSnapshotRuntime(?SnapshotRuntime $runtime): void
    {
        $this->compilingFor(new PlatformTarget(snapshotRuntime: $runtime ?? new NoSnapshotRuntime));
    }

    protected function target(): PlatformTarget
    {
        return $this->platformTarget ??= new PlatformTarget;
    }

    protected function compileService(ServiceSpec $spec): ManifestSet
    {
        return new ServiceCompiler($this->target())->compile($spec);
    }

    protected function compileDatabase(DatabaseSpec $spec): ManifestSet
    {
        $target = $this->target();
        $backups = new BackupCompiler($target);

        return new EngineDatabaseCompiler(
            new CnpgDatabaseCompiler($target, $backups),
            new StatefulDatabaseCompiler($target, $backups),
        )->compile($spec);
    }

    protected function compileBackup(BackupSpec $spec): ManifestSet
    {
        return new BackupCompiler($this->target())->compile($spec);
    }

    protected function compileGateway(EnvironmentGatewaySpec $spec): ManifestSet
    {
        return new EnvironmentGatewayCompiler($this->target())->compile($spec);
    }

    protected function compileRun(RunSpec $spec): ManifestSet
    {
        return new RunJobCompiler(new ServiceCompiler($this->target()))->compile($spec);
    }
}
