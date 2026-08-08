<?php

declare(strict_types=1);

namespace Cbox\Platform\Tests\Fixtures;

use Cbox\Platform\Testing\CompilesPlatformIntent;

/**
 * Where the shipped testing trait is composed, so the analyser sees it.
 *
 * PHPStan checks a trait at its use site, not its declaration. Without this
 * class the one part of the package consumers are most likely to build on
 * would be the one part never analysed.
 */
class TraitComposition
{
    use CompilesPlatformIntent;
}
