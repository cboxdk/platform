<?php

declare(strict_types=1);

namespace Cbox\Platform\Service;

use LogicException;

/**
 * One environment variable taken from a Secret somebody else created.
 *
 * THE OTHER HALF OF SECRET ENV, and the half a production deployment usually
 * needs. `ServiceSpec::$envSecret` carries VALUES and the compiler writes the
 * Secret that holds them — which means whoever builds the spec holds the
 * plaintext. A reference carries only a NAME: the Secret is the operator's, made
 * on the cluster before the first deploy, and the value never passes through
 * the spec, the compiler, a plan, a diff or a golden file.
 *
 * Compiled to `valueFrom.secretKeyRef` on every container of the service — web,
 * every process, a run, the release — and never `optional`. A Secret or a key
 * that does not exist leaves the pod in `CreateContainerConfigError`, which is
 * Kubernetes refusing to start a process without a value it was promised; a
 * pod that booted with an empty APP_KEY instead would be the worse failure.
 *
 * Validated here, because every one of these strings reaches the API server and
 * its refusal arrives mid-deploy in a vocabulary nobody opted into.
 */
readonly class SecretReference
{
    public function __construct(
        /** The environment variable the application reads. */
        public string $variable,
        /** The Secret, in the service's own namespace. */
        public string $secret,
        /** The key inside that Secret. */
        public string $key,
    ) {
        if (! self::isVariableName($variable)) {
            throw new LogicException(
                "[{$variable}] cannot be an environment variable name. Use letters, digits and "
                .'underscores, not starting with a digit — the only names every shell and runtime can read.'
            );
        }

        if (! self::isSecretName($secret)) {
            throw new LogicException(
                "[{$secret}] cannot be a Secret name. Use lower-case letters, digits, hyphens and dots, "
                .'start and end with a letter or digit, and keep it to 253 characters.'
            );
        }

        if (! self::isKey($key)) {
            throw new LogicException(
                "[{$key}] cannot be a key in a Secret. Use letters, digits, '-', '_' and '.', "
                .'up to 253 characters, and not \'.\' or \'..\'.'
            );
        }
    }

    /** A C identifier: what POSIX shells, PHP's getenv() and every runtime agree on. */
    public static function isVariableName(string $name): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1;
    }

    /** RFC 1123 subdomain, which is what Kubernetes requires of a Secret's name. */
    public static function isSecretName(string $name): bool
    {
        return strlen($name) <= 253
            && preg_match('/^[a-z0-9]([-a-z0-9]*[a-z0-9])?(\.[a-z0-9]([-a-z0-9]*[a-z0-9])?)*$/', $name) === 1;
    }

    /** What the API server accepts as a key under a Secret's `data`. */
    public static function isKey(string $key): bool
    {
        return strlen($key) <= 253
            && preg_match('/^[-._a-zA-Z0-9]+$/', $key) === 1
            && $key !== '.'
            && ! str_starts_with($key, '..');
    }

    /**
     * The container env entry.
     *
     * @return array{name: string, valueFrom: array{secretKeyRef: array{name: string, key: string}}}
     */
    public function toEnv(): array
    {
        return [
            'name' => $this->variable,
            'valueFrom' => ['secretKeyRef' => ['name' => $this->secret, 'key' => $this->key]],
        ];
    }
}
