<?php

declare(strict_types=1);

namespace ApiSkeletons\Laravel\Doctrine\Filter\Enums;

class OperatorEnum
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
