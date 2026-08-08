<?php

declare(strict_types=1);

namespace Cbox\Platform\Binding;

/**
 * Where a database's connection details actually live IN THE CLUSTER.
 *
 * The point of this object is that Cortex never carries the password. Both
 * engines already keep it in a Secret in the customer's own cluster — one
 * Cortex compiles for Valkey and Percona, one CloudNativePG generates for
 * Postgres — so a binding resolves to a `secretKeyRef` at that Secret rather
 * than to a copied value.
 *
 * That is stronger than resolving the credential fresh on every deploy, which
 * was the previous design: the control plane never holds it at all, and a
 * rotated password reaches the workload on the pod's next start without a
 * deploy and without Cortex being involved.
 */
readonly class ConnectionSource
{
    /**
     * @param  string  $secretName  the Secret in the database's namespace
     * @param  array<string, string>  $secretKeys  field value ⇒ key inside that Secret
     * @param  array<string, string>  $plain  field value ⇒ literal, for what is not a secret
     */
    public function __construct(
        public string $secretName,
        public array $secretKeys,
        public array $plain,
    ) {}

    /**
     * The scheme a URL for this engine starts with.
     */
    public static function scheme(string $engine): string
    {
        return match ($engine) {
            'postgres' => 'postgresql',
            'valkey' => 'redis',
            default => 'mysql',
        };
    }
}
