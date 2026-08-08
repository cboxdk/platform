<?php

declare(strict_types=1);

namespace Cbox\Platform\Service;

use Cbox\Platform\Binding\BindingSpec;

/**
 * The compiler's input: a fully-resolved, immutable snapshot of one service's
 * intent. The compiler is a pure function of this object — no models, no IO.
 */
readonly class ServiceSpec
{
    /**
     * @param  array<string, string>  $env  values safe to read — inlined in the pod spec
     * @param  array<string, string>  $envSecret  values that must never be readable from
     *                                            the cluster, compiled into a Secret
     * @param  bool  $scaleToZero  the service may idle to zero and wake on request
     * @param  bool  $suspended  the customer has explicitly pinned it down
     */
    public function __construct(
        public string $serviceId,
        public string $organizationId,
        public string $namespace,
        public string $name,
        public string $image,
        public int $port,
        public int $replicas,
        public array $env = [],
        public array $envSecret = [],
        /** @var list<BindingSpec> resolved database bindings */
        public array $bindings = [],
        /** The customer's registry credential, when their image is private. */
        public ?RegistrySpec $registry = null,
        /** @var list<ProcessSpec> workers and schedulers — everything that does not serve */
        public array $processes = [],
        /** @var list<string> */
        public array $domains = [],
        /** @var list<VolumeSpec> */
        public array $volumes = [],
        public ?int $autoscaleMin = null,
        public ?int $autoscaleMax = null,
        public ?int $autoscaleCpuPercent = null,
        public bool $scaleToZero = false,
        public int $idleTimeoutSeconds = 300,
        public bool $suspended = false,
        /**
         * The Cortex base image this service RUNS on, when the build was
         * layered.
         *
         * Empty for a Dockerfile build or a prebuilt image, where `image` above
         * is the whole story.
         *
         * When it is set, the two swap roles: the CONTAINER runs this, and
         * `image` is mounted onto it as an OCI image volume holding only the
         * application. That is what makes a CVE in PHP or the base OS a tag
         * move rather than a fleet-wide rebuild, and a PHP version change a
         * deploy rather than a migration.
         */
        public string $baseImage = '',
        /** Where the application is mounted on the base image. */
        /**
         * Where the artifact is mounted, and it must match where the base
         * image serves from.
         *
         * `/var/www/html` — checked against the image rather than assumed: that
         * is what its nginx and PHP-FPM are configured for, and it is where the
         * documented templates build into. Mounted at `/app` instead, nginx
         * serves an empty document root and nothing reports an error.
         */
        public string $appMountPath = '/var/www/html',
        /**
         * What this service asks of the image it runs on — supervised
         * processes, PHP and OPcache tuning, startup hooks.
         *
         * Only meaningful on a Cortex base image, which is the only image that
         * carries cbox-init to act on any of it. A Dockerfile build or a
         * prebuilt image gets none of it compiled, because setting
         * `LARAVEL_SCHEDULER` on an image with nothing reading it is a switch
         * that reports itself on and does nothing.
         */
        public ?RuntimeSettings $runtime = null,
        /**
         * The tenant cluster's pod range, so nginx knows which addresses may
         * speak for a client.
         *
         * Read from the cluster this service deploys to rather than assumed:
         * the range is a per-cluster setting, and trusting the wrong one means
         * either refusing the gateway's forwarded address or accepting one from
         * somewhere else.
         */
        public string $podCidr = '',
        /**
         * What this service asks of a node, and what it may not exceed.
         *
         * Null takes {@see ResourceRequirements::defaults()} — the values that
         * were compiled in before this was expressible, so a service nobody has
         * sized compiles exactly as it did.
         */
        public ?ResourceRequirements $resources = null,
        /**
         * The larger thing this service belongs to — a project, a system, a
         * product — for `app.kubernetes.io/part-of`.
         *
         * Empty omits the label rather than inventing a grouping. It is the one
         * recommended label with no answer anywhere else in a spec: name is the
         * service, instance is this deployment of it, and neither says what it
         * is a piece of.
         */
        public string $partOf = '',
        /**
         * Objects the platform does not model, deployed with this service.
         *
         * Carried so they participate: an object applied by hand is invisible to
         * plan/diff and never pruned when the service goes. Whether any are
         * allowed at all is the target's policy, not the spec's.
         *
         * @var list<CustomResource>
         */
        public array $customResources = [],
    ) {}

    /** What this service asks of a node, defaults included. */
    public function resources(): ResourceRequirements
    {
        return $this->resources ?? ResourceRequirements::defaults();
    }

    /**
     * Does this service scale on load?
     *
     * All three or none: a max with no target has nothing to scale on, and a
     * target with no max has nowhere to stop.
     */
    public function autoscales(): bool
    {
        return $this->autoscaleMin !== null
            && $this->autoscaleMax !== null
            && $this->autoscaleCpuPercent !== null;
    }
}
