<?php

declare(strict_types=1);

namespace ApiSkeletons\Doctrine\QueryBuilder\Filter;

use DateTime;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use Exception;

use function array_map;
use function array_search;
use function explode;
use function in_array;
use function is_array;
use function strpos;
use function strtolower;
use function substr;
use function trim;
use function uniqid;

class Applicator
{
    /**
     * The entity manager for the passed $entityClass
     * is required.  By passing in the entity manager
     * this class remains framework agnostic.
     */
    private EntityManager $entityManager;

    /**
     * Only the entity class name is required.
     */
    private string $entityClass;

    /**
     * The entityAlias is the alias used for the
     * $entityClass when building the Query Builder.
     * You may set the entity alias to a specific
     * value if you need to post-process the
     * Query Builder.
     */
    private string $entityAlias = 'entity';

    /**
     * The $entityAliasMap is a map of all entities
     * used in the Query Builder.  This is generated
     * data and useful if you want to post-process
     * the Query Builder.
     *
     * @var string[]
     */
    private array $entityAliasMap = [];

    /**
     * An array of field names which can be filtered.
     * Defaults to all entity fields.
     *
     * @var string[]
     * */
    private array $filterableFields = ['*'];

    /**
     * A map of query field names to entity field
     * names.  This allows you to alias fields in
     * your query.
     *
     * @var string[]
     */
    private array $fieldAliases = [];

    /**
     * A flag to enable deep queries using
     * relationships.  Defaults to false.
     */
    private bool $enableRelationships = false;

    /**
     * An array of allowed operators.  To remove
     * an operator from this list use removeOperator()
     * method
     *
     * @var string[]
     */
    private array $operators = [];

    public function __construct(EntityManager $entityManager, string $entityClass)
    {
        $this->entityManager = $entityManager;
        $this->entityClass   = $entityClass;
        $this->operators     = Operators::toArray();
    }

    /**
     * You may remove available operators such as removing `like`
     */
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

    /**
     * When called this will enable deep queries using relationships
     */
    public function enableRelationships(): self
    {
        $this->enableRelationships = true;

        return $this;
    }

    /**
     * Override the default entity alias
     */
    public function setEntityAlias(string $entityAlias): self
    {
        if (! $entityAlias) {
            throw new Exception('Entity alias cannot be empty');
        }

        $this->entityAlias = $entityAlias;

        return $this;
    }

    /**
     * Set the array of field aliases for aliasing filters
     *
     * @param string[] $fieldAliases
     */
    public function setFieldAliases(array $fieldAliases): self
    {
        $this->fieldAliases = $fieldAliases;

        return $this;
    }

    /**
     * Set the array of filterable fields to limit what user can filer on
     *
     * @param string[] $filterableFields
     */
    public function setFilterableFields(array $filterableFields): self
    {
        $this->filterableFields = $filterableFields;

        return $this;
    }

    /**
     * This is used after a Query Builder has been created.  It maps entity
     * classes to aliases used in the Query builder.
     *
     * @return string[]
     */
    public function getEntityAliasMap(): array
    {
        return $this->entityAliasMap;
    }

    /**
     * This is the entry point to create a QueryBuidler based on passed
     * filters
     *
     * @param string[] $filters
     */
    public function __invoke(array $filters): QueryBuilder
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select($this->entityAlias)
            ->from($this->entityClass, $this->entityAlias);

        if (! $filters) {
            return $queryBuilder;
        }

        foreach ($filters as $query => $value) {
            if (! is_array($value)) {
                $this->applyFilter($queryBuilder, $query, $value, $this->entityClass, $this->entityAlias);
            }

            $this->applyRelationship($queryBuilder, $query, $value, $this->entityClass, $this->entityAlias);
        }

        // Debugging
        // print_r($queryBuilder->getQuery()->getSql()); die();

        return $queryBuilder;
    }

    /**
     * Apply an individual filter to the QueryBuilder
     */
    private function applyFilter(QueryBuilder $queryBuilder, string $query, string $value, string $entityClass, string $alias): void
    {
        $fieldName = $this->getFieldName($query);
        $operator  = $this->getOperator($query);

        if (
            $this->filterableFields !== ['*']
            && ! in_array($fieldName, $this->filterableFields)
        ) {
            return;
        }

        $classMetadata = $queryBuilder->getEntityManager()->getClassMetadata($entityClass);

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
              // die('field not found: ' . $entityClass . '::' . $fieldName . ' alias ' . $alias);
                return;
            }
        }

        if (isset($mappingName)) {
            $associationMapping       = $classMetadata->getAssociationMapping($mappingName);
            $sourceAssociationMapping = $queryBuilder->getEntityManager()->getClassMetadata($associationMapping['sourceEntity']);
            $sourceIdentifierMapping  = $sourceAssociationMapping->getFieldMapping($sourceAssociationMapping->getIdentifier()[0]);
            $fieldType                = $sourceIdentifierMapping['type'];
        } else {
            $fieldMapping = $classMetadata->getFieldMapping($fieldName);
            $fieldType    = $fieldMapping['type'];
        }

        if ($operator) {
            $formattedValue                     = $this->formatValue($value, $fieldType, $operator);
            $this->entityAliasMap[$entityClass] = $alias;
            $this->applyWhere($queryBuilder, $fieldName, $formattedValue, $operator, $fieldType, $alias);
        }

        return;
    }

    /**
     * Create a join for deep filtering
     */
    private function applyRelationship(QueryBuilder $queryBuilder, string $query, mixed $value, string $entityClass, string $alias): void
    {
        if (! $this->enableRelationships) {
            return;
        }

        $classMetadata = $queryBuilder->getEntityManager()->getClassMetadata($entityClass);

        $fieldAssociationMapping = null;
        foreach ($classMetadata->getAssociationMappings() as $associationMapping) {
            if ($query === $associationMapping['fieldName']) {
                $fieldAssociationMapping = $associationMapping;
                break;
            }
        }

        if (! $fieldAssociationMapping) {
            return;
        }

        $queryBuilder->join($alias . '.' . $query, $query);
        $this->entityAliasMap[$entityClass] = $alias;

        foreach ($value as $q => $v) {
            if (is_array($v)) {
                $this->applyRelationship($queryBuilder, $q, $v, $fieldAssociationMapping['targetEntity'], $fieldAssociationMapping['fieldName']);
            } else {
                $this->applyFilter($queryBuilder, $q, $v, $fieldAssociationMapping['targetEntity'], $fieldAssociationMapping['fieldName']);
            }
        }

        return;
    }

    /**
     * Given a query field, extract the field name.
     * `name|neq` becomes `name`
     */
    private function getFieldName(string $value): string
    {
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

    /**
     * Given a query field, extract the operator
     * `name|neq` becomes `neq`
     */
    private function getOperator(string $query): ?string
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

    /**
     * Given a query value, format it based on metadata field type.
     */
    private function formatValue(string $value, string $fieldType, string $operator): mixed
    {
        $value = trim($value);

        if (strpos($value, ',') === false) {
            switch ($fieldType) {
                case 'int':
                case 'integer':
                    return (int) $value;

                case 'datetime':
                    return new DateTime($value);

                default:
                    switch ($operator) {
                        case Operators::LIKE:
                            return '%' . $value . '%';

                        case Operators::SORT:
                            return strtolower($value) === 'asc' ? 'asc' : 'desc';

                        default:
                            return $value;
                    }
            }
        }

        $value = explode(',', $value);
        $value = array_map(static function ($value) use ($fieldType) {
            switch ($fieldType) {
                case 'int':
                case 'integer':
                    return (int) $value;

                case 'bigint':
                    // bigint is handled as a string internally to PHP and cannot be typecast to an int
                default:
                    return $value;
            }
        }, $value);

        return $value;
    }

    /**
     * Apply a where clause to the QueryBuilder
     */
    private function applyWhere(QueryBuilder $queryBuilder, string $columnName, mixed $value, string $operator, string $fieldType, string $alias): void
    {
        switch ($operator) {
            case Operators::EQ:
            case Operators::NEQ:
            case Operators::IN:
            case Operators::NOTIN:
            case Operators::LT:
            case Operators::LTE:
            case Operators::GT:
            case Operators::GTE:
                $uniqid = 'q' . uniqid(); // Cannot start a parameter with a number
                $queryBuilder
                    ->andWhere($queryBuilder->expr()->$operator($alias . '.' . $columnName, ':' . $uniqid))
                    ->setParameter($uniqid, $value);
                break;
            case Operators::ISNULL:
            case Operators::ISNOTNULL:
                $queryBuilder->andWhere($queryBuilder->expr()->$operator($alias . '.' . $columnName));
                break;
            case Operators::LIKE:
                $uniqid = 'q' . uniqid(); // Cannot start a parameter with a number
                $queryBuilder
                    ->andWhere($queryBuilder->expr()->like($alias . '.' . $columnName, ':' . $uniqid))
                    ->setParameter($uniqid, $value);
                break;
            case Operators::BETWEEN:
                $uniqid1 = 'q' . uniqid(); // Cannot start a parameter with a number
                $uniqid2 = 'q' . uniqid(); // Cannot start a parameter with a number
                $queryBuilder
                    ->andWhere($queryBuilder->expr()->between($alias . '.' . $columnName, ':' . $uniqid1, ':' . $uniqid2))
                    ->setParameter($uniqid1, $value[0])
                    ->setParameter($uniqid2, $value[1]);
                break;
            case Operators::SORT:
                $queryBuilder->addOrderBy($alias . '.' . $columnName, $value);
                break;
            default:
        }
    }
}
