<?php

declare(strict_types=1);

namespace Cbox\Platform\Plan;

/**
 * What applying a plan will do to one object. Delete covers objects that
 * were applied before but are absent from the newly compiled set.
 */
enum PlanAction: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
    case Unchanged = 'unchanged';
}
