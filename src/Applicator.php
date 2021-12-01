<?php

namespace ApiSkeletons\Laravel\Doctrine\Filter;

use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\ORM\QueryBuilder;

class Applicator
{
    private $fieldAliases = [];  # todo
    private $entityClass;
    private $entityAlias = 'entity';
    private $filterableFields = ['*'];
    private $operators = [
        'eq',
        'neq',
        'gt',
        'gte',
        'lt',
        'lte',
        'between',
        'like',
        'in',
        'notIn',
        'isNull',
        'isNotNull',
    ];

    public function __construct(string $entityClass)
    {
        $this->setEntityClass($entityClass);
    }

    private function setEntityClass(string $entityClass): self
    {
        $this->entityClass = $entityClass;

        return $this;
    }

    public function removeOperator(string|array $operator): self
    {
        if (is_array($operator)) {
            foreach ($operator as $needle) {
                $index = array_search($needle, $this->operators, true);
                if ($index !== false) {
                    unset($this->operators[$index]);
                }
            }
        } else {
            $index = array_search($operator, $this->operators, true);
            if ($index !== false) {
                unset($this->operators[$index]);
            }
        }

        return $this;
    }

    public function setEntityAlias(string $entityAlias): self
    {
        if (! $entityAlias) {
            throw new \Exception('Entity alias cannot be empty');
        }

        $this->entityAlias = $entityAlias;

        return $this;
    }

    public function setFilterableFields(array $filterableFields): self
    {
        $this->filterableFields = $filterableFields;

        return $this;
    }

    public function applyFilters(array $filters): QueryBuilder
    {
        $managerRegistry = app(ManagerRegistry::class);
        $entityManager = $managerRegistry->getManagerForClass($this->entityClass);

        $queryBuilder = $entityManager->createQueryBuilder();
        $queryBuilder
            ->select($this->entityAlias)
            ->from($this->entityClass, $this->entityAlias)
            ;

        if (! $filters) {
            return $queryBuilder;
        }

        foreach ($filters as $query => $value) {
            $this->applyFilter($queryBuilder, $query, $value);
        }

        return $queryBuilder;
    }

    private function applyFilter(QueryBuilder $queryBuilder, string $query, string $value): self
    {
        $fieldName = $this->getFieldName($query);
        $operator = $this->getOperator($query);

        if ($this->filterableFields !== ['*']
            && ! in_array($fieldName, $this->filterableFields)) {

            return $this;
        }

        $classMetadata = $queryBuilder->getEntityManager()->getClassMetadata($this->entityClass);

        // Verify the field exists on the entity
        if (! $classMetadata->hasField($fieldName)) {
            $found = false;
            foreach ($classMetadata->getAssociationMappings() as $name => $association) {
                if ($association['fieldName'] === $fieldName) {
                    $found = true;
                    $mappingName = $name;
                    break;
                }
            }

            if (! $found) {
                return $this;
            }
        }

        if (isset($mappingName)) {
            $associationMapping = $classMetadata->getAssociationMapping($mappingName);
            $sourceAssociationMapping = $queryBuilder->getEntityManager()->getClassMetadata($associationMapping['sourceEntity']);
            $sourceIdentifierMapping = $sourceAssociationMapping->getFieldMapping($sourceAssociationMapping->getIdentifier()[0]);
            $columnType = $sourceIdentifierMapping['type'];
        } else {
            $fieldMapping = $classMetadata->getFieldMapping($fieldName);
            $columnType = $fieldMapping['type'];
        }

        $formattedValue = $this->formatValue($value, $columnType, $operator);

        $this->applyWhere($queryBuilder, $fieldName, $formattedValue, $operator, $columnType);

        return $this;
    }

    protected function getFieldName(string $value): string
    {
        if (strpos($value, '.') !== false) {
            $value = explode('.', $value);
        }

        if (strpos($value, '|') === false) {
            return trim($value);
        }

        return trim(substr($value, 0, strpos($value, '|')));
    }

    private function getOperator(string $query): string
    {
        if (strpos($query, '|') === false && in_array('eq', $this->operators)) {
            return 'eq';
        }

        $query = trim(substr($query, strpos($query, '|') + 1));
        $query = strtolower($query);

        if (in_array($query, $this->operators)) {
            return $query;
        }

        return null;
    }

    private function formatValue($value, $columnType, $operator): array|int|string
    {
        if (strpos($value, ',') === false) {
            return $columnType === 'int' || $columnType === 'integer' || $columnType === 'bigint'
            ? (int) $value
            : ($operator === Enums\OperatorEnum::LIKE ? "'%" . strtolower($value) . "%'" : "'" . trim($value) . "'");
        }

        $value = explode(',', $value);

        $value = array_map(static function ($value) use ($columnType, $operator) {
            return $columnType === 'int' || $columnType === 'integer' || $columnType === 'bigint'
            ? (int) $value
            : ($operator === Enums\OperatorEnum::LIKE ? "'%" . strtolower($value) . "%'" :  trim($value));
        }, $value);

        return $value;
    }

    private function applyWhere(QueryBuilder $queryBuilder, $columnName, $value, $operator, $columnType): void
    {
        $alias = $this->entityAlias;

        if (empty($operator)) {
            if (is_array($value)) {
                $operator = Enums\OperatorEnum::IN;
            } else {
                $operator = Enums\OperatorEnum::EQ;
            }
        }

        if ($columnType === 'jsonb') {
          // fixme
          die('jsonb not supported');
//            $this->applyLaravelDoctrineJsonbWhere($queryBuilder, $alias, $columnName, $value, $operator, $columnType);

            return;
        }

        switch ($operator) {
            case Enums\OperatorEnum::EQ:
            case Enums\OperatorEnum::NEQ:
            case Enums\OperatorEnum::IN:
            case Enums\OperatorEnum::NOTIN:
            case Enums\OperatorEnum::LT:
            case Enums\OperatorEnum::LTE:
            case Enums\OperatorEnum::GT:
            case Enums\OperatorEnum::GTE:
                $queryBuilder->andWhere($queryBuilder->expr()->$operator($alias . '.' . $columnName, $value));
                break;
            case Enums\OperatorEnum::ISNULL:
            case Enums\OperatorEnum::ISNOTNULL:
                $queryBuilder->andWhere($queryBuilder->expr()->$operator($alias . '.' . $columnName));
                break;
            case Enums\OperatorEnum::LIKE:
                $queryBuilder->andWhere($queryBuilder->expr()->$operator('LOWER(' . $alias . '.' . $columnName . ')', $value));
                break;
            case Enums\OperatorEnum::BETWEEN:
                $queryBuilder->andWhere($queryBuilder->expr()->$operator($alias . '.' . $columnName, "'" . $value[0] . "'", "'" . $value[1] . "'"));
                break;
        }
    }
}