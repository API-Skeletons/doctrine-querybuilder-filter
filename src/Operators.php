<?php

declare(strict_types=1);

namespace ApiSkeletons\Laravel\Doctrine\Filter;

final class Operators
{
    public const EQ        = 'eq';
    public const NEQ       = 'neq';
    public const GT        = 'gt';
    public const GTE       = 'gte';
    public const LT        = 'lt';
    public const LTE       = 'lte';
    public const BETWEEN   = 'between';
    public const LIKE      = 'like';
    public const IN        = 'in';
    public const NOTIN     = 'notIn';
    public const ISNULL    = 'isNull';
    public const ISNOTNULL = 'isNotNull';
}
