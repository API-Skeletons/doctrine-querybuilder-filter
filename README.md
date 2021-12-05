# Doctrine QueryBuilder Filter

[![Build Status](https://github.com/API-Skeletons/doctrine-querybuilder-filter/actions/workflows/continuous-integration.yml/badge.svg)](https://github.com/API-Skeletons/doctrine-querybuilder-filter/actions/workflows/continuous-integration.yml?query=branch%3Amain)
[![Code Coverage](https://codecov.io/gh/API-Skeletons/doctrine-querybuilder-filter/branch/main/graphs/badge.svg)](https://codecov.io/gh/API-Skeletons/doctrine-querybuilder-filter/branch/main)
[![PHP Version](https://img.shields.io/badge/PHP-8.0-blue)](https://img.shields.io/badge/PHP-8.0-blue)
[![Total Downloads](https://poser.pugx.org/api-skeletons/doctrine-querybuilder-filter/downloads)](//packagist.org/packages/api-skeletons/doctrine-querybuilder-filter)
[![License](https://poser.pugx.org/api-skeletons/doctrine-querybuilder-filter/license)](//packagist.org/packages/api-skeletons/doctrine-querybuilder-filter)


Apply filters to a QueryBuilder based on request parameters.  Supports deep queries using joins.
This repository is intened to apply query parameters to filter entity data.

## Installation

Run the following to install this library using [Composer](https://getcomposer.org/):

```bash
composer require api-skeletons/doctrine-querybuilder-filter
```

## Quick Start

```php
use ApiSkeletons\Doctrine\QueryBuilder\Filter\Applicator;

$applicator = (new Applicator($entityManager, Entity\User::class));
$queryBuilder = $applicator($_REQUEST['filter']);
```

## Filters

The pattern for creating a filter is `filter[fieldName|operator]=value`
This pattern is an easy way to define complex queries utilizing all the filtering
capabilities of the QueryBuilder.

The following URL will provide a LIKE filter for a user's `name`:

```sh
http://localhost/api/user?filter[name|like]=John
```

These operators are supported:

* eq - equals.  If no operator is specified then this is the default
* neq - does not equal
* gt - greater than
* gte - greater than or equals
* lt - less than
* lte - less than or equals
* between - between two values comma delmited e.g. `filter[id|between]=1,5`
* like - a fuzzy search which wraps the value in wildcards
* in - a list of values to match comma delmited e.g. `filter[id|in]=1,2,3]`
* notin - the opposite of an `in` operator
* isnull - any value is acceptable a only the field will be checked for null
* isnotnull - the opposite of an `isNull` operator
* sort - sort the result on the field either `asc` or `desc`

You may use as many filters as you wish.  Filters operate on the field names of
the entity and on the association names.  This allows you to filter on an
assocaition.  For instance, to filter a list of users by company where the id
of the company is known and the Doctrine metadata is correct you may filter
like this:

```sh
http://localhost/api/user?filter[company]=10
```

So even though there is not a field named company there is a association and
that is filterable though this tool.

### Filtering on single entities

The configuration of the Applicator allows you to enable assocaition filtering,
but this is disabled by default.  So, assuming the default setting, this is a
complex filter on a single entity:

```sh
http://localhost/api/user?filter[company|neq]=15&filter[name]=John
```

### Filtering on the hierarcy of entities

The configuration of the Applicator allows you to enable assocation filtering.
This means you can filter the current entity based on fields with an association
with the current entity and you may do so as deep as you wish.

In this example we'll pull user data based on their company by name:

```sh
http://localhost/api/user?filter[company][name|eq]=AAA
```

In this example we'll pull user data based on their company by company type by
company type name:

```sh
http://localhost/api/user?filter[company][companyType][name|neq]=Consultant
```

Before you get too concerned about this ability remember you can modify the
QueryBuilder after it has had filters applied to it so if you need to apply
security settings that is supported.

### A note on sorting

While sorting isn't by strict definition a filter, it falls into the same
context when requesting data.  You can sort by multiple fields and you can
sort across associations (if enabled).  Sorting is prioritized left to right.

## Use

This is the minimum required to use this library:

```php
use ApiSkeletons\Doctrine\QueryBuilder\Filter\Applicator;

$applicator = (new Applicator($entityManager, Entity\User::class));
$queryBuilder = $applicator($_REQUEST['filter']);
```

This is an example of configuring the applicator with all possible options

```php
use ApiSkeletons\Doctrine\QueryBuilder\Filter\Applicator;

$applicator = (new Applicator($entityManager, Entity\User::class))
    ->enableRelationships()
    ->removeOperator('like')
    ->setEntityAlias('user')
    ->setFieldAliases(['firstName' => 'name'])
    ->setFilterableFields(['id', 'name'])
    ;
$queryBuilder = $applicator($_REQUEST['filter']);

$entityAliasMap = $applicator->getEntityAliasMap();
```

After a QueryBuilder is returned with the filters applied to it you may modify
it as desired before using it to fetch your result.

## Real world Laravel example

In this example, data from the `Entity\Style` entity is returned using
[HAL](https://github.com/API-Skeletons/laravel-hal) in a paginated response
in a controller action.

```php
use ApiSkeletons\Doctrine\QueryBuilder\Filter\Applicator;
use Doctrine\ORM\Tools\Pagination\Paginator;
use HAL;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;


public function fetchAll(Request $request)
{
    $filter = $request->query()['filter'];
    if (! is_array($filter)) {
        $filter = [];
    ]
    
    $page = (int) $request->query()['page'] ?? 1;
    if ($page < 1) {
        $page = 1;
    }

    $applicator = (new Applicator(app('em'), Entity\Style::class))
        ->enableRelationships(true);
    $queryBuilder = $applicator($filter);

    $paginator = new Paginator($queryBuilder);
    $paginator->getQuery()
        ->setFirstResult(25 * ($page - 1)) // Page is 0 indexed in query
        ->setMaxResults(25)
        ;

    $data = (new LengthAwarePaginator(iterator_to_array($paginator->getIterator()), $paginator->count(), 25))
        ->appends($request->query())
        ->withPath(route('api.style::fetchAll'))
        ;

    return HAL::paginate('style', $data)->toArray();
}
```

## Configuration

Using the Applicator is strait-forward and many options for configuring it are
available.  Begin by creating the Applicator then run configuration functions:

```php
$applicator = new Applicator($entityManager, Entity\Style::class);
```

### enableAssociations()

This configuration method turns on deep filtering by using the Doctrine
metadata to traverse the ORM through existing joins to the target entity.
This is only possible in a Doctrine installation with complete metadata and
proper associations between entities defined in the metadata.

### removeOperator(string|array)

If you want to disable any operator you may remove it.  A good example is the
`like` operator which could result in expensive queries.

### setEntityAlias(string)

The default alias used when creating filters for the target entity in the
QueryBuilder is `entity`. You may want to change this so you know the alias
after the QueryBuilder is returned and you can add additional parameters to it.

### setFieldAliases(array)

If you want the filter to alias a field instead of using the ORM field name
(such as using naming strategies in hydrators) you may pass an array of
`[alias] => field` values so mapping can be adjusted.

### setFilterableFields(array)

By default all fields on the target entity can be filtered.  If you want to
limit which fields your users can create filters for then pass those field
names in this array.

### getEntityAliasMap()

This method is for post-processing of the target entity.  When users use
deep filtering using `enableAssociations()` aliases are created for every
entity joined to the original entity query.  This method returns an array of
`[entityClass] => alias` for all entities joined in the QueryBuilder.
