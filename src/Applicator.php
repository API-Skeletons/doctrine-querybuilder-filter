<?php

declare(strict_types=1);

namespace ApiSkeletons\Laravel\Doctrine\Filter;

use Doctrine\ORM\EntityManager;
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
    private EntityManager $entityManager;

    private string $entityClass;

    private string $entityAlias = 'entity';

    /** @var string[] */
    private array $filterableFields = ['*'];

    /** @var string[] */
    private array $fieldAliases = [];

    /** @var string[] */
    private array $operators = [
        Operators::EQ,
        Operators::NEQ,
        Operators::GT,
        Operators::GTE,
        Operators::LT,
        Operators::LTE,
        Operators::BETWEEN,
        Operators::LIKE,
        Operators::IN,
        Operators::NOTIN,
        Operators::ISNULL,
        Operators::ISNOTNULL,
    ];

    public function __construct(EntityManager $entityManager, string $entityClass)
    {
        $this->entityManager = $entityManager;
        $this->entityClass   = $entityClass;
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
     * @param string[] $fieldAliases
     */
    public function setFieldAliases(array $fieldAliases): self
    {
        $this->fieldAliases = $fieldAliases;

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
        $queryBuilder = $this->entityManager->createQueryBuilder()
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
            $fieldName = trim($value);

            if (isset($this->fieldAliases[$fieldName])) {
                return $this->fieldAliases[$fieldName];
            }

            return $fieldName;
        }

        $fieldName = trim(substr($value, 0, strpos($value, '|')));

        if (isset($this->fieldAliases[$fieldName])) {
            return $this->fieldAliases[$fieldName];
        }

        return $fieldName;
    }

    private function getOperator(string $query): string
    {
        if (strpos($query, '|') === false && in_array(Operators::EQ, $this->operators)) {
            return Operators::EQ;
        }

        $query = trim(substr($query, strpos($query, '|') + 1));
        $query = strtolower($query);

        if (in_array($query, $this->operators)) {
            return $query;
        }

        return null;
    }

    private function formatValue(string $value, string $columnType, string $operator): mixed
    {
        if (strpos($value, ',') === false) {
            return $columnType === 'int' || $columnType === 'integer' || $columnType === 'bigint'
            ? (int) $value
            : ($operator === Operators::LIKE ? "'%" . strtolower($value) . "%'" : "'" . trim($value) . "'");
        }

        $value = explode(',', $value);

        $value = array_map(static function ($value) use ($columnType, $operator) {
            return $columnType === 'int' || $columnType === 'integer' || $columnType === 'bigint'
            ? (int) $value
            : ($operator === Operators::LIKE ? "'%" . strtolower($value) . "%'" :  trim($value));
        }, $value);

        return $value;
    }

    private function applyWhere(QueryBuilder $queryBuilder, string $columnName, mixed $value, string $operator, string $columnType): void
    {
        $alias = $this->entityAlias;

        if (empty($operator)) {
            if (is_array($value)) {
                $operator = Operators::IN;
            } else {
                $operator = Operators::EQ;
            }
        }

        if ($columnType === 'jsonb') {
            $this->applyJsonbWhere($queryBuilder, $columnName, $value, $operator, $columnType);

            return;
        }

        switch ($operator) {
            case Operators::EQ:
            case Operators::NEQ:
            case Operators::IN:
            case Operators::NOTIN:
            case Operators::LT:
            case Operators::LTE:
            case Operators::GT:
            case Operators::GTE:
                $queryBuilder->andWhere($queryBuilder->expr()->$operator($alias . '.' . $columnName, $value));
                break;
            case Operators::ISNULL:
            case Operators::ISNOTNULL:
                $queryBuilder->andWhere($queryBuilder->expr()->$operator($alias . '.' . $columnName));
                break;
            case Operators::LIKE:
                $queryBuilder->andWhere($queryBuilder->expr()->$operator('LOWER(' . $alias . '.' . $columnName . ')', $value));
                break;
            case Operators::BETWEEN:
                $queryBuilder->andWhere($queryBuilder->expr()->$operator($alias . '.' . $columnName, "'" . $value[0] . "'", "'" . $value[1] . "'"));
                break;
        }
    }

    private function applyJsonbWhere(QueryBuilder $queryBuilder, string $columnName, mixed $value, string $operator, string $columnType): void
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
