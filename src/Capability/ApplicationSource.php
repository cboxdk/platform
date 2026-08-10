<?php

declare(strict_types=1);

namespace Cbox\Platform\Capability;

use LogicException;

/**
 * Where a service's application code comes from.
 *
 * Normally: an OCI image, pulled by the kubelet and mounted read-only. That is
 * the only answer a hosted platform can give, and it is why a deploy is a tag
 * and a rollback is the previous tag.
 *
 * On a DEVELOPMENT MACHINE the answer has to be different or the product does
 * not exist. Somebody editing a file wants the next request to run it; a build
 * and a push between the two is the thing they are trying to get away from. So
 * the same base image runs, and the application arrives from the developer's own
 * disk instead of from a registry.
 *
 * DENY BY DEFAULT, and this one matters more than most. A hostPath mount reads
 * and writes the NODE's filesystem: on a shared cluster it is a container escape
 * with extra steps, and the request for it arrives inside customer intent — a
 * ServiceSpec — where it would otherwise be honoured without anybody deciding
 * to. A target has to say yes, and a spec asking a target that did not is
 * refused rather than quietly compiled without it.
 */
readonly class ApplicationSource
{
    private function __construct(
        public bool $fromHost,
        public string $nodePrefix,
    ) {}

    /** An image, pulled and mounted read-only. What every cluster does. */
    public static function image(): self
    {
        return new self(false, '');
    }

    /**
     * The node's own filesystem, at a path prefix the substrate provides.
     *
     * The PREFIX exists because a developer's directory is not at the same path
     * inside the node: a kind cluster is a container, and the host's
     * `/Users/x/app` is only visible there because it was mounted somewhere —
     * conventionally under a prefix. Translating here rather than at the caller
     * keeps the spec talking about the path the DEVELOPER knows, which is the
     * one in their editor's title bar and the only one they can check.
     */
    public static function hostPath(string $nodePrefix = ''): self
    {
        return new self(true, rtrim($nodePrefix, '/'));
    }

    /**
     * Where the node sees a path the developer knows.
     *
     * @throws LogicException when the target does not allow it at all
     */
    public function nodePath(string $hostPath): string
    {
        if (! $this->fromHost) {
            throw new LogicException(
                'This service asks to run code from a path on the machine, and this platform serves '
                .'applications from images. A hostPath mount reads and writes the node itself, so it '
                .'is refused unless the target asked for it — see ApplicationSource::hostPath().',
            );
        }

        if (! str_starts_with($hostPath, '/')) {
            throw new LogicException(
                "[{$hostPath}] has to be an absolute path: a relative one means something different "
                .'in every directory anybody runs a command from.',
            );
        }

        return $this->nodePrefix.rtrim($hostPath, '/');
    }
}
