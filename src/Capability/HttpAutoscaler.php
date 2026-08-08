<?php

declare(strict_types=1);

namespace Cbox\Platform\Capability;

/**
 * Where a request goes while a scaled-to-zero workload is waking up.
 *
 * On the cold-start tier the route points at an interceptor proxy instead of
 * the workload, so the first request can be buffered while the pod starts
 * rather than refused. The proxy is part of the target's installed stack, not
 * of the application, which is why its coordinates are a property of the
 * target and not of any spec.
 *
 * The defaults are the KEDA HTTP add-on's, which is what both Cbox Cortex and
 * Cbox Local install. A target that deploys it elsewhere — a different
 * namespace, a different release name — says so here rather than by patching
 * the compiler.
 */
readonly class HttpAutoscaler
{
    public function __construct(
        public string $name = 'keda-add-ons-http-interceptor-proxy',
        public string $namespace = 'keda',
        public int $port = 8080,
    ) {}
}
