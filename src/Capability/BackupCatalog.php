<?php

declare(strict_types=1);

namespace Cbox\Platform\Capability;

use Cbox\Platform\Database\DatabaseEngine;
use RuntimeException;

/**
 * The engine images a target runs, and where its backups are keyed.
 *
 * Both were read from configuration inside the value objects before the
 * extraction, which meant a compiler that looked pure was in fact resolving
 * two settings through a global at the moment it emitted a manifest. Passing
 * them makes the compiler honest — and makes it possible for a test to compile
 * a backup Job without an application booted around it.
 */
readonly class BackupCatalog
{
    /**
     * @param  array<string, string>  $imageRepositories  keyed by engine, WITHOUT a tag
     */
    public function __construct(
        public array $imageRepositories = [
            'percona' => 'ghcr.io/cboxdk/percona',
            'valkey' => 'ghcr.io/cboxdk/valkey',
        ],
        /**
         * Key prefix inside the bucket; the organization and database are
         * appended, so one bucket holds every tenant without collision — and a
         * tenant's access key has a single prefix to be restricted to.
         */
        public string $keyPrefix = 'cbox',
    ) {}

    /**
     * The engine image for a version. Composed rather than configured whole so
     * a backup can never run a different build than the database it is backing
     * up.
     */
    public function image(DatabaseEngine $engine, string $version): string
    {
        $repository = $this->imageRepositories[$engine->value] ?? '';

        if ($repository === '') {
            throw new RuntimeException("No engine image configured for [{$engine->value}].");
        }

        return $repository.':'.$version;
    }

    /**
     * The key prefix everything an organization writes lives under — the scope
     * an operator must restrict its access key to. Stated in one place so the
     * error message, the docs and the compiled key can never disagree.
     */
    public function prefix(string $organizationId): string
    {
        $prefix = $this->keyPrefix !== '' ? trim($this->keyPrefix, '/').'/' : '';

        return $prefix.$organizationId;
    }
}
