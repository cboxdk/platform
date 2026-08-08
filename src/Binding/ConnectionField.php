<?php

declare(strict_types=1);

namespace Cbox\Platform\Binding;

/**
 * The fields a database can hand to a workload.
 *
 * An enum rather than magic strings, and the reason is the `secret()` split
 * below: it decides whether a field is inlined in the pod spec or referenced
 * from the database's own Secret, and getting that wrong for one field is how
 * a password ends up readable in `kubectl get deploy -o yaml`.
 */
enum ConnectionField: string
{
    case Host = 'host';
    case Port = 'port';
    case Database = 'database';
    case User = 'user';
    case Password = 'password';

    /**
     * The whole connection string, composed IN THE POD from the fields above
     * using Kubernetes' own `$(VAR)` expansion — so the password is still only
     * ever a Secret reference, and Cortex never assembles a URL it would have
     * had to hold the password to build.
     */
    case Url = 'url';

    /**
     * Whether this field must be referenced from a Secret rather than written
     * into the pod spec.
     *
     * A host and a port are not secrets; treating them as such buys nothing
     * and makes every binding noisier. A password is, and so is a URL that
     * contains one.
     */
    public function secret(): bool
    {
        return match ($this) {
            self::Password, self::Url => true,
            default => false,
        };
    }
}
