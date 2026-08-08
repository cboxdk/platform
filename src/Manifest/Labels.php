<?php

declare(strict_types=1);

namespace Cbox\Platform\Manifest;

use Cbox\Platform\Capability\PlatformIdentity;

/**
 * What a Kubernetes label value may be, and how to read a version out of an
 * image reference.
 *
 * Two pure questions with no installation-specific answer. Everything that
 * depends on WHO owns an object — the prefix, the field manager — belongs to
 * {@see PlatformIdentity}, because a package two
 * products share cannot have one product's name compiled into it.
 */
readonly class Labels
{
    /**
     * The version of the running artifact, from its image reference.
     *
     * A tag is a version; a digest is not, and does not fit a label value
     * anyway — `sha256:…` is 71 characters and contains a colon, so emitting it
     * would fail the apply rather than describe anything. Null means the
     * question has no answer, and the label is left off.
     */
    public static function versionFrom(string $image): ?string
    {
        // Strip any registry host:port before looking for the tag separator,
        // or `registry:5000/app` reads as the tag `5000/app`.
        $lastSlash = strrpos($image, '/');
        $reference = $lastSlash === false ? $image : substr($image, $lastSlash + 1);

        if (str_contains($reference, '@')) {
            return null;
        }

        $colon = strrpos($reference, ':');

        if ($colon === false) {
            return null;
        }

        $tag = substr($reference, $colon + 1);

        return self::isValidValue($tag) ? $tag : null;
    }

    /**
     * Whether Kubernetes would accept this as a label VALUE.
     *
     * Up to 63 characters, alphanumeric at each end, with dashes, underscores
     * and dots between. An empty value is legal in Kubernetes but says nothing,
     * so it is treated as absent here.
     */
    public static function isValidValue(string $value): bool
    {
        return $value !== ''
            && strlen($value) <= 63
            && preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9._-]*[a-zA-Z0-9])?$/', $value) === 1;
    }
}
