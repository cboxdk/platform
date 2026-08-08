<?php

declare(strict_types=1);

namespace Cbox\Platform\Service;

/**
 * How PHP-FPM sizes its worker pool.
 *
 * The base image auto-tunes from the container's cgroup limits — it reads
 * `memory.max`, reserves headroom for nginx, OPcache and the system, and derives
 * `pm.max_children` from what is left. A 512 MB container yields about four
 * workers.
 *
 * WHICH IS WHY THIS IS A PROFILE AND NOT A NUMBER. A static `pm.max_children`
 * is a guess about a container whose memory limit the customer can change in the
 * next screen, and guessing high is how a pod gets OOM-killed under exactly the
 * load it was sized for.
 */
enum FpmProfile: string
{
    case Dev = 'dev';
    case Light = 'light';
    case Medium = 'medium';
    case Heavy = 'heavy';
    case Bursty = 'bursty';

    public function label(): string
    {
        return match ($this) {
            self::Dev => 'Development',
            self::Light => 'Light — small requests, lots of them',
            self::Medium => 'Medium (default)',
            self::Heavy => 'Heavy — few requests, each expensive',
            self::Bursty => 'Bursty — idle, then spikes',
        };
    }
}
