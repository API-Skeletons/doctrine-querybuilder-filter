<?php

declare(strict_types=1);

namespace ApiSkeletons\Laravel\Doctrine\Filter;

use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\ORM\QueryBuilder;
use Exception;

use function array_map;
use function array_search;
use function count;
use function explode;
use function in_array;
use function is_array;
use function strpos;
use function strtolower;
use function substr;
use function trim;

class Applicator
{
    /** @var string[] */
    private array $fieldAliases = [];  // todo

    private string $entityClass;

    private string $entityAlias = 'entity';

    /** @var string[] */
    private array $filterableFields = ['*'];

    /** @var string[] */
    private array $operators = [
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
                if ($index === false) {
                    continue;
                }

                unset($this->operators[$index]);
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
            throw new Exception('Entity alias cannot be empty');
        }

        $this->entityAlias = $entityAlias;

        return $this;
    }

    /**
     * @param string[] $filterableFields
     */
    public function setFilterableFields(array $filterableFields): self
    {
        $this->filterableFields = $filterableFields;

        return $this;
    }

    /**
     * @param string[] $filters
     */
    public function applyFilters(array $filters): QueryBuilder
    {
        $managerRegistry = app(ManagerRegistry::class);
        $entityManager   = $managerRegistry->getManagerForClass($this->entityClass);

        $queryBuilder = $entityManager->createQueryBuilder();
        $queryBuilder
            ->select($this->entityAlias)
            ->from($this->entityClass, $this->entityAlias);

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
        $operator  = $this->getOperator($query);

        if (
            $this->filterableFields !== ['*']
            && ! in_array($fieldName, $this->filterableFields)
        ) {
            return $this;
        }

        $classMetadata = $queryBuilder->getEntityManager()->getClassMetadata($this->entityClass);

        // Verify the field exists on the entity
        if (! $classMetadata->hasField($fieldName)) {
            $found = false;
            foreach ($classMetadata->getAssociationMappings() as $name => $association) {
                if ($association['fieldName'] === $fieldName) {
                    $found       = true;
                    $mappingName = $name;
                    break;
                }
            }

            if (! $found) {
                return $this;
            }
        }

        if (isset($mappingName)) {
            $associationMapping       = $classMetadata->getAssociationMapping($mappingName);
            $sourceAssociationMapping = $queryBuilder->getEntityManager()->getClassMetadata($associationMapping['sourceEntity']);
            $sourceIdentifierMapping  = $sourceAssociationMapping->getFieldMapping($sourceAssociationMapping->getIdentifier()[0]);
            $columnType               = $sourceIdentifierMapping['type'];
        } else {
            $fieldMapping = $classMetadata->getFieldMapping($fieldName);
            $columnType   = $fieldMapping['type'];
        }

        if ($operator) {
            $formattedValue = $this->formatValue($value, $columnType, $operator);
            $this->applyWhere($queryBuilder, $fieldName, $formattedValue, $operator, $columnType);
        }

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
        if (strpos($query, '|') === false && in_array(Enums\OperatorEnum::EQ, $this->operators)) {
            return Enums\OperatorEnum::EQ;
        }

        $query = trim(substr($query, strpos($query, '|') + 1));
        $query = strtolower($query);

        if (in_array($query, $this->operators)) {
            return $query;
        }

        return null;
    }

    private function formatValue(string $value, string $columnType, string $operator): array|int|string
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

    private function applyWhere(QueryBuilder $queryBuilder, string $columnName, string|int|bool $value, string $operator, string $columnType): void
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
            $this->applyJsonbWhere($queryBuilder, $columnName, $value, $operator, $columnType);

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

    private function applyJsonbWhere(QueryBuilder $queryBuilder, string $columnName, string|int|bool $value, string $operator, string $columnType): void
    {
        $alias = $this->entityAlias;

        $path = null;
        if (is_array($columnName)) {
            for ($i = 0; $i < count($columnName); $i++) {
                if ($i === 0) {
                    continue;
                }

                $currentColumn  = $columnName[$i];
                $previousColumn = $i - 1 === 0
                ? $alias . '.' . $columnName[$i - 1]
                : $columnName[$i - 1];

                if ($i === count($columnName) - 1) {
                    $path = empty($path)
                    ? 'JSON_GET_FIELD_AS_TEXT(' . $currentColumn . ', \'' . $previousColumn . '\')'
                    : 'JSON_GET_FIELD_AS_TEXT(' . $path . ', \'' . $currentColumn . '\')';
                    break;
                }

                $path = empty($path)
                    ? 'JSON_GET_FIELD(' . $previousColumn . ', \'' . $currentColumn . '\')'
                    : 'JSON_GET_FIELD(' . $path . ', \'' . $currentColumn . '\')';
            }
        } else {
            $path = $alias . '.' . $columnName;
        }

        switch ($operator) {
            case OperatorEnum::EQ:
            case OperatorEnum::NEQ:
            case OperatorEnum::IN:
            case OperatorEnum::NOTIN:
            case OperatorEnum::LT:
            case OperatorEnum::LTE:
            case OperatorEnum::GT:
            case OperatorEnum::GTE:
                $queryBuilder->andWhere($queryBuilder->expr()->$operator($path, $value));
                break;
            case OperatorEnum::ISNULL:
            case OperatorEnum::ISNOTNULL:
                $queryBuilder->andWhere($queryBuilder->expr()->$operator($path));
                break;
            case OperatorEnum::LIKE:
                $queryBuilder->andWhere($queryBuilder->expr()->$operator('LOWER(' . $path . ')', $value));
                break;
            case OperatorEnum::BETWEEN:
                $queryBuilder->andWhere($queryBuilder->expr()->$operator($path, "'" . $value[0] . "'", "'" . $value[1] . "'"));
                break;
            default:
                break;
        }
    }
}
