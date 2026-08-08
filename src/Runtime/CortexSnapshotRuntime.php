<?php

declare(strict_types=1);

namespace Cbox\Platform\Runtime;

use Cbox\Platform\Contracts\SnapshotRuntime;

/**
 * Cortex's own warm-restore runtime: `cbox-init` cooperates with `cortex-agent`
 * instead of a containerd shim pretending a stopped container is running.
 *
 * The shape of this class is the whole argument for building it. There is no
 * RuntimeClass, because nothing about the container is special — it is scheduled
 * by the ordinary runtime, and stays genuinely running the entire time it is
 * asleep, since our supervisor is PID 1 and only its children are checkpointed.
 * That is why {@see runtimeClassName()} is null while {@see isAvailable()} is
 * true, a combination the contract was written to allow.
 *
 * The annotations are read by `cortex-agent` on the node, which is the component
 * with the privileges to run CRIU. `cbox-init` inside the container reads none
 * of them: it learns the port and the idle timeout from the agent when it
 * registers, over the control socket it already holds. So the environment below
 * carries only what a process must know about itself before anyone has spoken to
 * it — where to find the agent, and the two settings CRIU needs from the
 * workload.
 *
 * Those two are not our invention and not negotiable. A Go process with
 * Multipath TCP enabled opens a socket CRIU cannot dump — confirmed directly on
 * a node, where the dump failed with `inet: Unsupported proto 262` — and an
 * OPcache JIT buffer leaves an RWX region CRIU cannot inject its dump parasite
 * through.
 *
 * Not promised, deliberately: established TCP connections do not survive a
 * checkpoint. A checkpoint is only taken when there are none, and a post-restore
 * hook re-establishes what the workload owned outbound.
 *
 * This is the default runtime. It earned that by the head-to-head the design
 * made binding — same node, same workload, same resident set, eight wakes each,
 * medians: 80 ms against 119 at 9 MB, 134 against 170 at 77 MB, 275 against 341
 * at 280 MB, 453 against 497 at 530 MB. Both implementations are CRIU 4.2 on
 * one kernel, so the page-restore term is common to both and the difference is
 * the machinery around it.
 */
class CortexSnapshotRuntime implements SnapshotRuntime
{
    /**
     * Where `cortex-agent` listens, bind-mounted into the container by the node.
     * A fixed path rather than a configurable one: both ends are ours, and a
     * setting that must match on both sides of a privilege boundary is a way to
     * get it wrong.
     */
    public const SOCKET = '/run/cortex/snapshot.sock';

    /** The annotation namespace is ours — this runtime is not a vendor's. */
    private const PREFIX = 'snapshot.cboxcortex.com/';

    public function __construct(private readonly bool $available = true) {}

    public function isAvailable(): bool
    {
        return $this->available;
    }

    /**
     * None. The pod runs under the node's ordinary runtime; the checkpointing
     * happens beneath a supervisor we already ship, not beneath a shim.
     */
    public function runtimeClassName(): ?string
    {
        return null;
    }

    public function annotations(string $containerName, int $port, int $idleTimeoutSeconds): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        return [
            self::PREFIX.'container-names' => $containerName,
            self::PREFIX.'ports-map' => $containerName.'='.$port,
            self::PREFIX.'idle-timeout' => $idleTimeoutSeconds.'s',
        ];
    }

    public function environment(): array
    {
        return [
            'GODEBUG' => 'multipathtcp=0',
            'PHP_OPCACHE_JIT' => 'off',
            'CBOX_SNAPSHOT_SOCKET' => self::SOCKET,
        ];
    }

    /**
     * Where the workload itself listens, which is never the port clients reach.
     *
     * `cbox-init` holds the service port so a connection's handshake completes
     * while the workload is asleep, so the workload has to be somewhere else.
     * The offset is a convention rather than a discovery because both ends are
     * ours: the compiler moves the workload here, and `cbox-init` is told the
     * service port by the agent at registration and proxies between the two.
     *
     * Absent this variable `cbox-init` declines the warm tier and runs the
     * container normally, which is the correct behaviour for any image that
     * does not let us choose where its server listens.
     */
    public const UPSTREAM_PORT_OFFSET = 10000;

    /**
     * The upstream address for a workload whose service port is $port.
     */
    public static function upstream(int $port): string
    {
        return '127.0.0.1:'.($port + self::UPSTREAM_PORT_OFFSET);
    }
}
