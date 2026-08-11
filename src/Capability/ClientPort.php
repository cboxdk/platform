<?php

declare(strict_types=1);

namespace Cbox\Platform\Capability;

/**
 * The port a client actually reaches the gateway on.
 *
 * ALMOST ALWAYS 443, WHICH IS WHY THIS COMPILES NOTHING BY DEFAULT. A hosted
 * cell owns its load balancer and answers on the standard port, so there is
 * nothing to tell anybody.
 *
 * A DEVELOPMENT MACHINE IS THE EXCEPTION, and the exception is invisible until
 * something breaks. When the local gateway has to take 18443 because another
 * tool holds 443, every URL the application generates — a login redirect, a
 * password-reset link, an OAuth callback — drops the port and points at
 * whatever else is on 443. The application cannot work this out for itself:
 * Gateway API strips the port from `:authority`, so by the time the request
 * reaches the pod there is nothing left to read.
 *
 * SO THE ROUTE CARRIES IT. `X-Forwarded-Port` on the request is the one place
 * the number survives the hop, and the runtime turns it into the `Host` the
 * framework builds URLs from.
 */
readonly class ClientPort
{
    private function __construct(public int $port) {}

    /** What every hosted cluster does: the standard HTTPS port, told to nobody. */
    public static function standard(): self
    {
        return new self(443);
    }

    /**
     * A port that is not the default, and therefore has to be announced.
     */
    public static function nonStandard(int $port): self
    {
        if ($port < 1 || $port > 65535) {
            throw new \LogicException("[{$port}] is not a port a client can reach.");
        }

        return new self($port);
    }

    /** Whether anything needs compiling for it. */
    public function announced(): bool
    {
        return $this->port !== 443;
    }
}
