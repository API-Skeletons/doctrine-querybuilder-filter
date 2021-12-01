<?php

declare(strict_types=1);

namespace ApiSkeletons\Laravel\Doctrine\Filter\Entities;

use LaravelDoctrine\ORM\Facades\EntityManager;

use function explode;
use function in_array;
use function property_exists;

class FilterEntity
{
  /** @var array */
    protected static array $availableFields = ['*'];

  /** @var array */
    protected static array $entityJoins = [];

    public static function getEntityAlias()
    {
        if (property_exists(static::class, 'entityAlias')) {
            return static::class::$entityAlias;
        }

        return snake_case(array_last(explode('\\', static::class)));
    }

  /**
   * @return array
   */
    public static function getEntityJoins(): array
    {
        $entityName = static::class;

        $joins = [];

        foreach ($entityName::$entityJoins as $key => $join) {
            $entity                          = $join['entity'];
            $joins[$key]['entity']           = $join['entity'];
            $joins[$key]['condition']        = $join['condition'];
            $joins[$key]['available_fields'] = $entity::getAvailableFields();
        }

        return $joins;
    }

  /**
   * @return array
   */
    public static function getAvailableFields(): array
    {
        $fields = EntityManager::getClassMetadata(static::class)->fieldMappings;

        $availableFields = [];

        foreach ($fields as $key => $field) {
            if (! in_array($field['columnName'], self::$availableFields) && ! in_array('*', self::$availableFields)) {
                continue;
            }

            $availableFields[$field['columnName']] = ['type' => $field['type'], 'fieldName' => $field['fieldName']];
        }

        return $availableFields;
    }
}
