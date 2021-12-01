<?php

declare(strict_types=1);

namespace ApiSkeletons\Laravel\Doctrine\Filter\Validation\Rules\Filter;

use DateTime;
use Illuminate\Contracts\Validation\ImplicitRule;
use Throwable;

use function array_key_exists;
use function count;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function strpos;
use function strtolower;
use function substr;
use function trim;

class IsValidFilter implements ImplicitRule
{
    private string $entityClass = '';

  /** @var array */
    private array $filters = [];

  /** @var array */
    private array $unavailableFields = [];
    private $unavailableJoins        = [];
    private $unavailableOperators    = [];
    private $unavailableValues       = [];
    private $unavailableValuesByType = [];
    private $isValid                 = true;

  /**
   * @param array $filters
   */
    public function __construct(string $entityClass, array $filters = [])
    {
        $this->entityClass = $entityClass;
        $this->filters     = $filters;
    }

    public function passes(string $attribute, mixed $value): bool
    {
        if (empty($this->filters)) {
            return true;
        }

        foreach ($this->filters as $attributeName => $value) {
            if (! is_array($value)) {
                $this->getUnavailableFields($attributeName, $value, $this->entityClass);
                continue;
            }

            $this->getUnavailableRelationships($attributeName, $value, $this->entityClass);
        }

        return $this->isValid;
    }

    public function message(): ?string
    {
        $message = null;

        $invalidFields    = implode(', ', $this->unavailableFields);
        $invalidJoins     = implode(', ', $this->unavailableJoins);
        $invalidOperators = implode(', ', $this->unavailableOperators);

        if (! empty($invalidFields)) {
            $message = empty($message)
            ? $message . 'invalid field(s): ' . $invalidFields
            : $message . ' | invalid field(s): ' . $invalidFields;
        }

        if (! empty($invalidJoins)) {
            $message = empty($message)
            ? $message . 'invalid join(s): ' . $invalidJoins
            : $message . ' | invalid join(s): ' . $invalidJoins;
        }

        if (! empty($invalidOperators)) {
            $message = empty($message)
            ? $message . 'invalid operator(s): ' . $invalidOperators
            : $message . ' | invalid operator(s): ' . $invalidOperators;
        }

        for ($i = 0; $i < count($this->unavailableValues); $i++) {
            $message = empty($message)
            ? $message . 'operator ' . "'" . $this->unavailableValues[$i]['operator'] . "'" . ' must receive a valid value, value given: ' . "'" . $this->unavailableValues[$i]['value'] . "'"
            : $message . ' | ' . 'operator ' . "'" . $this->unavailableValues[$i]['operator'] . "'" . ' must receive a valid value, value given: ' . "'" . $this->unavailableValues[$i]['value'] . "'";
        }

        for ($i = 0; $i < count($this->unavailableValuesByType); $i++) {
            $message = empty($message)
            ? $message . 'field type ' . "'" . $this->unavailableValuesByType[$i]['fieldType'] . "'" . ' must receive a valid value, value given: ' . "'" . $this->unavailableValuesByType[$i]['value'] . "'"
            : $message . ' | ' . 'field type ' . "'" . $this->unavailableValuesByType[$i]['fieldType'] . "'" . ' must receive a valid value, value given: ' . "'" . $this->unavailableValuesByType[$i]['value'] . "'";
        }

        return $message;
    }

    protected function prepareColumnName($value): array|string
    {
        if (strpos($value, '.') !== false) {
            $value = explode('.', $value);
        }

        if (is_array($value)) {
            if (strpos($value[count($value) - 1], '|') === false) {
                return $value;
            }

            $value[count($value) - 1] = trim(substr($value[count($value) - 1], 0, strpos($value[count($value) - 1], '|')));

            return $value;
        }

        if (strpos($value, '|') === false) {
            return trim($value);
        }

        return trim(substr($value, 0, strpos($value, '|')));
    }

    protected function getUnavailableFields($attributeName, $value, $entityClass): void
    {
        $columnNameFixed = self::prepareColumnName($attributeName);

        if (! is_array($columnNameFixed)) {
            if (! array_key_exists($columnNameFixed, $entityClass::getAvailableFields())) {
                $this->unavailableFields[] = $columnNameFixed;
                $this->isValid             = false;

                return;
            }
        } elseif (! array_key_exists(array_first($columnNameFixed), $entityClass::getAvailableFields())) {
            $this->unavailableFields[] = array_first($columnNameFixed);
            $this->isValid             = false;

            return;
        }

        $operator = $this->getUnavailableOperators($attributeName);

        is_array($columnNameFixed)
        ?  $this->getUnavailableValues($value, $operator, $entityClass::getAvailableFields()[array_first($columnNameFixed)])
        :  $this->getUnavailableValues($value, $operator, $entityClass::getAvailableFields()[$columnNameFixed]);
    }

    protected function getUnavailableRelationships($joinName, $values, $entityName): void
    {
        $entityJoins = $entityName::getEntityJoins();

        if (! array_key_exists($joinName, $entityJoins)) {
            $this->unavailableJoins[] = $joinName;
            $this->isValid            = false;

            return;
        }

        foreach ($values as $jn => $v) {
            if (is_array($v)) {
                $this->getUnavailableRelationships($jn, $v, $entityJoins[$joinName]['entity']);
            } else {
                $this->getUnavailableFields($jn, $v, $entityJoins[$joinName]['entity']);
            }
        }
    }

    protected function getUnavailableOperators($value): string
    {
        if (strpos($value, '|') === false) {
            return '';
        }

        $value = trim(substr($value, strpos($value, '|') + 1));

        switch (strtolower(camel_case($value))) {
            case 'eq':
            case 'neq':
            case '=':
            case 'gt':
            case '>':
            case 'gte':
            case '>=':
            case 'lt':
            case '<':
            case 'lte':
            case '<=':
            case 'between':
            case 'like':
            case 'in':
            case 'notin':
            case 'isnull':
            case 'isnotnull':
                return strtolower(camel_case($value));

            default:
                $this->unavailableOperators[] = $value;
                $this->isValid                = false;

                return '';
        }
    }

    protected function getUnavailableValues($value, $operator, $field): void
    {
        $valueGiven = $value;

        if (strpos($value, ',') !== false) {
            $value = explode(',', $value);
        }

        if (empty($operator) && is_array($value)) {
            $operator = 'in';
        }

        if (empty($operator) && ! is_array($value)) {
            $operator = 'eq';
        }

        switch (strtolower(camel_case($operator))) {
            case 'eq':
            case 'neq':
            case '=':
            case 'gt':
            case '>':
            case 'gte':
            case '>=':
            case 'lt':
            case '<':
            case 'lte':
            case '<=':
            case 'like':
                if (empty($value) || is_array($value)) {
                    $this->isValid             = false;
                    $this->unavailableValues[] = ['operator' => $operator, 'value' => $valueGiven];
                }

                if (
                    in_array(strtolower(camel_case($field['type'])), ['datetime', 'date', 'carbondatetime', 'carbondate']) &&
                    ! $this->isDate($value)
                ) {
                    $this->isValid                   = false;
                    $this->unavailableValuesByType[] = ['fieldType' => $field['type'], 'value' => $valueGiven];
                }

                break;
            case 'between':
            case 'in':
            case 'notin':
                if (empty($value) || ! is_array($value)) {
                    $this->isValid             = false;
                    $this->unavailableValues[] = ['operator' => $operator, 'value' => $valueGiven];
                }

                if (
                    in_array(strtolower(camel_case($field['type'])), ['datetime', 'date', 'carbondatetime', 'carbondate']) &&
                    (! $this->isDate($value[0]) || ! $this->isDate($value[1]))
                ) {
                    $this->isValid                   = false;
                    $this->unavailableValuesByType[] = ['fieldType' => $field['type'], 'value' => $valueGiven];
                }

                break;
        }
    }

    function isDate($value): bool
    {
        if (! $value) {
            return false;
        }

        try {
            new DateTime($value);

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}
