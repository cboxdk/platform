<?php

declare(strict_types=1);

namespace Cbox\Platform\Service;

use Cbox\Platform\Capability\ApplicationSource;
use LogicException;

/**
 * A directory on the machine, mounted somewhere in the container.
 *
 * DEVELOPMENT ONLY, and the target decides whether it is honoured at all — the
 * same gate as {@see ApplicationSource}, for the same
 * reason: a hostPath reads and writes the node's filesystem, and the request for
 * one arrives inside customer intent.
 *
 * It exists for the case a single source path cannot express: a package being
 * developed, installed into a throwaway application by composer and then
 * OVERLAID by the developer's real directory, so an edit is live without the
 * application having to reach outside its own tree. Mounting it inside the
 * application's own path is what keeps it inside `open_basedir`; mounting it
 * beside would need the runtime's own restrictions widened, which is a worse
 * trade than an extra mount.
 */
readonly class SourceMount
{
    public function __construct(
        public string $hostPath,
        public string $mountPath,
    ) {
        foreach (['hostPath' => $hostPath, 'mountPath' => $mountPath] as $what => $path) {
            if (! str_starts_with($path, '/')) {
                throw new LogicException(
                    "A mount's {$what} has to be absolute; [{$path}] means something different in "
                    .'every directory anybody runs a command from.'
                );
            }
        }
    }

    /** A DNS-label name for the volume, derived so two mounts cannot collide. */
    public function name(string $prefix): string
    {
        return $prefix.'-'.substr(hash('sha256', $this->mountPath), 0, 10);
    }
}
