<?php

declare(strict_types=1);

namespace ApiSkeletons\Doctrine\QueryBuilder\Filter;

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
    public const NOTIN     = 'notin';
    public const ISNULL    = 'isnull';
    public const ISNOTNULL = 'isnotnull';
    public const SORT      = 'sort';

    /**
     * @return string[]
     */
    public static function toArray(): array
    {
        return [
            self::EQ,
            self::NEQ,
            self::GT,
            self::GTE,
            self::LT,
            self::LTE,
            self::BETWEEN,
            self::LIKE,
            self::IN,
            self::NOTIN,
            self::ISNULL,
            self::ISNOTNULL,
            self::SORT,
        ];
    }
}
