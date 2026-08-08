<?php

declare(strict_types=1);

namespace Cbox\Platform\Service;

/**
 * OPcache's JIT mode.
 *
 * `tracing` is the base image's default and the right answer for almost
 * everything. It is here because the exceptions are real: a JIT bug in a hot
 * loop is diagnosed by turning it off, and an application doing heavy numeric
 * work sometimes measures better on `function`.
 */
enum OpcacheJit: string
{
    case Tracing = 'tracing';
    case Function = 'function';
    case Off = 'off';

    public function label(): string
    {
        return match ($this) {
            self::Tracing => 'Tracing (default)',
            self::Function => 'Function',
            self::Off => 'Off',
        };
    }
}
