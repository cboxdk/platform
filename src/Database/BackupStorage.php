<?php

declare(strict_types=1);

namespace Cbox\Platform\Database;

/**
 * Where a tenant's backups are written and under what identity.
 *
 * The identity is the tenant's own object-storage credential, resolved from
 * their organization — never a cell-wide key. That distinction is the whole
 * point: the compiled Secret lands in the customer's namespace, inside the
 * customer's cluster, where anyone with access to that namespace can read it. A
 * shared bucket key there hands every tenant the ability to read, overwrite and
 * delete every other tenant's backups. A tenant-scoped key can only reach the
 * tenant's own prefix.
 *
 * Placement (bucket, endpoint, region) is not a credential and may still come
 * from the cell's configuration — one bucket can hold every tenant, because the
 * key is what confines them, and a tenant that keeps backups in their own
 * account can override it on their credential.
 *
 * The keys are carried here only so the compiler can put them in a Secret. They
 * never reach a pod spec, an argument list or an environment literal.
 */
readonly class BackupStorage
{
    public function __construct(
        public string $bucket,
        public string $endpoint,
        public string $region,
        public string $accessKeyId,
        public string $secretAccessKey,
        public int $retainDays,
        /**
         * PEM of the authority that signed the endpoint's certificate, when the
         * system trust store cannot verify it on its own.
         *
         * This is what makes "TLS everywhere" reachable rather than aspirational.
         * A store Cortex issues a certificate to — from the tenant's own CA, so
         * it can be revoked and rotated — presents a chain no public authority
         * vouches for, and barman would refuse it. Without somewhere to put the
         * CA the only way to get a working backup is plaintext, which is how a
         * platform ends up shipping http:// and calling it internal.
         */
        public string $endpointCa = '',
    ) {}

    /**
     * Whether this destination is plausibly outside the cluster it backs up.
     *
     * A backup that lives on the same nodes as the database it protects survives
     * a dropped table and nothing worse — lose the cluster and the copy goes with
     * it. That is the case backups exist for, so the product should be able to
     * SAY when a destination has this property rather than leave the customer to
     * work it out from an endpoint URL.
     *
     * Deliberately conservative, and deliberately a heuristic: it recognises the
     * shapes that are certainly not off-site — a Kubernetes service name, a
     * loopback, or an RFC1918 address — and treats everything else as external.
     * The failure mode that matters is calling something off-site when it is not,
     * so the doubt goes the other way.
     */
    public function isOffsite(): bool
    {
        $host = strtolower((string) (parse_url($this->endpoint, PHP_URL_HOST) ?: $this->endpoint));

        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
            return false;
        }

        // Cluster-internal DNS: `minio.obj.svc`, `…svc.cluster.local`, and the
        // bare single-label form a Service resolves to inside its own namespace.
        if (str_ends_with($host, '.svc') || str_contains($host, '.svc.') || ! str_contains($host, '.')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) !== false;
        }

        return true;
    }
}
