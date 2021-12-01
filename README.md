# Doctrine QueryBuilder Filter

Apply filters to a QueryBuilder based on request parameters.  Supports deep queries using joins.

## Installation

Run the following to install this library using [Composer](https://getcomposer.org/):

```bash
composer require api-skeletons/doctrine-querybuilder-filter
```

## Use

```php
use ApiSkeletons\Doctrine\QueryBuilder\Filter\Applicator;

$applicator = (new Applicator($entityManager, Entity\User::class));
$queryBuilder = $applicator($filter);
```

---

## Examples

example1:

      url = http://localhost/advertisements?filter[created_at]=2018-07-22T18:48:16-03:00
      query =
             SELECT advertisement
             FROM Entity\Advertisement advertisement
             WHERE advertisement.createdAt = '2018-07-22 18:48:16'
 
        
 example2:
 
        url = http://localhost/advertisements?filter[id]=1,2
        query = 
                SELECT advertisement
                FROM Entity\Advertisement advertisement
                WHERE advertisement.id IN (1, 2)

example3:

      url = http://localhost/advertisements?filter[created_at|between]=2018-07-22T18:48:16-03:00,2018-07-22T18:48:16-03:00
      query =
             SELECT advertisement
             FROM Entity\Advertisement advertisement
             WHERE advertisement.createdAt BETWEEN '2018-07-22 18:48:16' AND '2018-07-22 18:48:16'

example 4:

      url = http://localhost/advertisements?filter[customer_product][customer][name|like]=cor
      query =
             SELECT advertisement
             FROM Entity\Advertisement advertisement
             INNER JOIN Entity\CustomerProduct customer_product
             WITH advertisement.customerProductId = customer_product.id
             INNER JOIN Entity\Customer customer
             WITH customer_product.customerId = customer.id
             WHERE LOWER(customer.name) LIKE '%cor%'


# Configuration Filter with Joins

In your entity add a static attribute $joins with this structure:

      protected static $entityJoins [
       'customer_product' => ['entity' => CustomerProduct::class, 'condition' => 'advertisement.customerProductId = customer_product.id'],
       'site' => ['entity' => Site::class, 'condition' => 'advertisement.siteId = site.id']
     ];


## Available Operators

* eq: equals
* neq: not equals
* gt: greater than
* gte: greater than or equals
* lt: less than
* lte: less than or equals
* between: between values.  Delimit with a comma
* like: a fuzzy query '%value%'
* in: in an array of values.  Delimit with a comma
* notin: not in an arry of values.  Delimit with a comma
* isnull: is the field null?
* isnotnull: is the field not null?

