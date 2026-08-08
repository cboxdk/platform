<?php

declare(strict_types=1);

namespace Cbox\Platform\Plan;

enum FieldChangeKind: string
{
    case Added = 'added';
    case Removed = 'removed';
    case Changed = 'changed';
}
