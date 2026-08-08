<?php

declare(strict_types=1);

namespace Cbox\Platform\Tests;

use Cbox\Platform\Testing\CompilesPlatformIntent;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Plain PHPUnit. No application, no container, no database — which is the
 * point: if the compiler needed any of them, this file could not exist and the
 * package would not be portable.
 */
abstract class TestCase extends BaseTestCase
{
    use CompilesPlatformIntent;

    /** The recorded output for a compiled set, as multi-document YAML. */
    protected function golden(string $name): string
    {
        return __DIR__.'/Golden/'.$name.'.golden.yaml';
    }
}
