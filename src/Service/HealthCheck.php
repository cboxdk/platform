<?php

declare(strict_types=1);

namespace Cbox\Platform\Service;

use LogicException;

/**
 * How the platform asks the web process whether it can serve, and whether it is
 * alive at all — two different questions with two different consequences.
 *
 * READINESS DECIDES TRAFFIC. A pod that fails it is taken out of the Service's
 * endpoints and, during a rollout, holds the rollout: the old pod is not
 * stopped until the new one is ready. This is where a deep check belongs —
 * database, cache, queue — because the answer to "can this instance serve?"
 * depends on them.
 *
 * LIVENESS DECIDES RESTARTS, and that is why it must be shallow. A liveness
 * probe on a deep check restarts every healthy pod the moment the database is
 * slow, which turns a degraded dependency into an outage. It should answer
 * "is this process running", and nothing more.
 *
 * A TOKEN ON READINESS, never on liveness. An application that refuses to
 * describe its dependencies to an anonymous caller is right to, and a Kubernetes
 * `httpGet` probe can only send STATIC headers — there is no `secretKeyRef` for
 * a probe header. Writing the token into the probe would put the value in the
 * pod spec, readable by anyone who can `kubectl get deploy -o yaml`. So a probe
 * with a token is compiled as an `exec` probe that reads the variable from the
 * container's own environment, where the reference already put it: the value
 * never leaves the Secret→env path. See ServiceCompiler for the command and
 * the one thing it needs from the image (`curl`).
 */
readonly class HealthCheck
{
    public function __construct(
        /** Readiness. Empty for none. */
        public string $readinessPath = '',
        /** Liveness. Empty for none — and then no startup probe either. */
        public string $livenessPath = '',
        /**
         * The environment variable holding a bearer token readiness requires.
         * Empty when the readiness path answers without one.
         */
        public string $tokenVariable = '',
    ) {
        if ($readinessPath === '' && $livenessPath === '') {
            throw new LogicException('A health check needs a readiness path, a liveness path, or both.');
        }

        foreach (['readiness' => $readinessPath, 'liveness' => $livenessPath] as $what => $path) {
            if ($path !== '' && ! self::isPath($path)) {
                throw new LogicException(
                    "[{$path}] cannot be the {$what} path. Write an absolute path such as /health/ready — "
                    .'letters, digits and . _ ~ % / + : @ = & ? , - only, with no spaces or quotes.'
                );
            }
        }

        if ($tokenVariable !== '' && ! SecretReference::isVariableName($tokenVariable)) {
            throw new LogicException(
                "[{$tokenVariable}] cannot be the health token's variable. Name the environment variable "
                .'that holds it — letters, digits and underscores.'
            );
        }

        if ($tokenVariable !== '' && $readinessPath === '') {
            throw new LogicException(
                'A health token is presented to the readiness path, and there is none. Liveness never '
                .'takes one: it has to answer even when the application cannot.'
            );
        }
    }

    /**
     * A path a probe can request, and that is safe inside a shell's single quotes.
     *
     * Narrower than RFC 3986 on purpose. The token probe runs through `sh -c`,
     * and a path is part of that line — so a quote, a backslash, a dollar sign
     * or a space is refused rather than escaped. No real health endpoint needs one.
     */
    public static function isPath(string $path): bool
    {
        return preg_match('#^/[A-Za-z0-9._~%/+:@=&?,-]*$#', $path) === 1;
    }

    public function authenticated(): bool
    {
        return $this->tokenVariable !== '';
    }
}
