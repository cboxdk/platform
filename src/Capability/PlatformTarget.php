<?php

declare(strict_types=1);

namespace Cbox\Platform\Capability;

use Cbox\Platform\Contracts\SnapshotRuntime;
use Cbox\Platform\Runtime\NoSnapshotRuntime;

/**
 * What the cluster being compiled for can actually do.
 *
 * ONE TYPED OBJECT, NOT A BRANCH. The compiler must never ask which product it
 * is running inside — `if ($local)` is how two consumers stop meaning the same
 * thing by "a Cbox application". Where two targets genuinely differ, the
 * difference is a value here, and it is as testable as any other input.
 *
 * ONLY WHAT IS REAL. Every member below corresponds to something the compiler
 * already branches on or reads. Capabilities are added when a second target
 * actually differs, not in anticipation of one — a flag nothing reads is a
 * switch that reports itself on and does nothing.
 *
 * The defaults reproduce Cbox Cortex's production behaviour exactly, which is
 * what the golden manifests are compiled against.
 */
readonly class PlatformTarget
{
    /**
     * How hostnames get a certificate.
     *
     * Not promoted, unlike its neighbours, because {@see Certificates} is built
     * through named constructors — there is no meaningful bare `new` for it, so
     * its default has to be computed rather than written inline.
     */
    public Certificates $certificates;

    /**
     * Which Gateway API implementation is installed. A class name no controller
     * claims compiles an object that never serves traffic.
     */
    public GatewayImplementation $gateway;

    /**
     * What a customer may deploy that the platform does not model. Denies
     * everything by default — a library that shipped an open door would put
     * every consumer one forgotten setting away from arbitrary objects in a
     * shared cluster.
     */
    public CustomResourcePolicy $customResources;

    public function __construct(
        /**
         * Who owns the compiled objects and what they are called. The label
         * prefix must be a domain you control; the field manager is recorded
         * in every object's ownership and must not change under a live cluster.
         */
        public PlatformIdentity $identity = new PlatformIdentity,
        /**
         * The node-side runtime that checkpoints an idle workload and restores
         * it on the next connection. Its absence is not an error: a target with
         * no snapshot runtime falls back to a cold start, which is a different
         * compiled shape rather than a degraded one.
         */
        public SnapshotRuntime $snapshotRuntime = new NoSnapshotRuntime,
        public HttpAutoscaler $httpAutoscaler = new HttpAutoscaler,
        public BackupCatalog $backups = new BackupCatalog,
        public CustomerAccess $customerAccess = new CustomerAccess,
        /**
         * Where pods land. A property of the cluster, never of the application:
         * a single-node kind cluster has nowhere to spread to, and a dedicated
         * node pool needs a toleration the application has never heard of.
         */
        public Placement $placement = new Placement,
        /**
         * The first capability two real targets disagree on: ACME needs the
         * authority to reach the hostname, which a local cluster cannot offer
         * at any price.
         *
         * Null takes the public-ACME default — the behaviour every existing
         * cluster already has.
         */
        ?Certificates $certificates = null,
        ?GatewayImplementation $gateway = null,
        ?CustomResourcePolicy $customResources = null,
    ) {
        $this->certificates = $certificates ?? Certificates::acme();
        $this->gateway = $gateway ?? GatewayImplementation::envoyGateway();
        $this->customResources = $customResources ?? CustomResourcePolicy::forbidden();
    }
}
