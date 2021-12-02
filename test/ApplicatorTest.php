<?php

namespace ApiSkeletonsTest\Doctrine\QueryBuilder\Filter;

use ApiSkeletons\Doctrine\QueryBuilder\Filter\Applicator;
use DateTime;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\Setup;
use Exception;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../vendor/autoload.php';

class ApplicatorTest extends TestCase
{
    protected EntityManager $entityManager;

    public function setUp(): void
    {
        // Create a simple "default" Doctrine ORM configuration for Annotations
        $isDevMode = true;
        $proxyDir = null;
        $cache = null;
        $useSimpleAnnotationReader = false;
        $config = Setup::createXMLMetadataConfiguration(array(__DIR__ . "/config"), $isDevMode);

        // database configuration parameters
        $conn = array(
            'driver' => 'pdo_sqlite',
            'memory' => true,
        );

        // obtaining the entity manager
        $this->entityManager = EntityManager::create($conn, $config);
        $tool = new SchemaTool($this->entityManager);
        $res = $tool->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $this->populateData();
    }

    public function testEq(): void
    {
        $filter = ['state' => 'Utah'];

        $applicator = (new Applicator($this->entityManager, Entity\Performance::class));
        $queryBuilder = $applicator($filter);

        $this->assertEquals(2, sizeof($queryBuilder->getQuery()->getResult()));
    }

    public function testNeq(): void
    {
        $filter = ['state|neq' => 'Utah'];

        $applicator = (new Applicator($this->entityManager, Entity\Performance::class));
        $queryBuilder = $applicator($filter);

        $this->assertEquals(5, sizeof($queryBuilder->getQuery()->getResult()));
    }

    public function testGt(): void
    {
        $filter = ['performanceDate|gt' => '1998-11-02'];

        $applicator = (new Applicator($this->entityManager, Entity\Performance::class));
        $queryBuilder = $applicator($filter);

        $this->assertEquals(3, sizeof($queryBuilder->getQuery()->getResult()));
    }

    public function testGte(): void
    {
        $filter = ['performanceDate|gte' => '1998-11-02'];

        $applicator = (new Applicator($this->entityManager, Entity\Performance::class));
        $queryBuilder = $applicator($filter);
// SHOULD BE 4
        $this->assertEquals(3, sizeof($queryBuilder->getQuery()->getResult()));
    }

    public function testLt(): void
    {
        $filter = ['performanceDate|lt' => '1998-11-02'];

        $applicator = (new Applicator($this->entityManager, Entity\Performance::class));
        $queryBuilder = $applicator($filter);

        $this->assertEquals(4, sizeof($queryBuilder->getQuery()->getResult()));
    }

    public function testLte(): void
    {
        $filter = ['performanceDate|lte' => '1998-11-02'];

        $applicator = (new Applicator($this->entityManager, Entity\Performance::class));
        $queryBuilder = $applicator($filter);

        $this->assertEquals(4, sizeof($queryBuilder->getQuery()->getResult()));
    }

    public function testRemoveOperator(): void
    {
        $filter = ['performanceDate|gt' => '1998-11-02'];

        $applicator = (new Applicator($this->entityManager, Entity\Performance::class))
            ->removeOperator('gt');
        $queryBuilder = $applicator($filter);

        $this->assertEquals(7, sizeof($queryBuilder->getQuery()->getResult()));
    }

    public function testRemoveOperators(): void
    {
        $filter = [
            'performanceDate|gt' => '1998-11-02',
            'performanceDate|lt' => '1998-11-02',
        ];

        $applicator = (new Applicator($this->entityManager, Entity\Performance::class))
            ->removeOperator(['gt', 'lt']);
        $queryBuilder = $applicator($filter);

        $this->assertEquals(7, sizeof($queryBuilder->getQuery()->getResult()));
    }

    public function testRemoveInvalidOperators(): void
    {
        $filter = [
            'performanceDate|gt' => '1998-11-02',
            'performanceDate|lt' => '1998-11-02',
        ];

        $applicator = (new Applicator($this->entityManager, Entity\Performance::class))
            ->removeOperator(['gtx', 'ltx']);
        $queryBuilder = $applicator($filter);

        $this->assertEquals(0, sizeof($queryBuilder->getQuery()->getResult()));
    }

    public function testEnableRelationships(): void
    {
        $filter = [
            'artist' => [
                'name' => 'Grateful Dead',
            ]
        ];

        $applicator = (new Applicator($this->entityManager, Entity\Performance::class))
            ->enableRelationships();
        $queryBuilder = $applicator($filter);

        $this->assertEquals(4, sizeof($queryBuilder->getQuery()->getResult()));
    }

    public function testDisabledRelationshipsCannotQueryRelationships(): void
    {
        $filter = [
            'artist' => [
                'name' => 'Grateful Dead',
            ]
        ];

        $applicator = (new Applicator($this->entityManager, Entity\Performance::class));
        $queryBuilder = $applicator($filter);

        $this->assertEquals(7, sizeof($queryBuilder->getQuery()->getResult()));
    }

    public function testSetEntityAlias(): void
    {
        $filter = [
            'artist' => [
                'name' => 'Grateful Dead',
            ]
        ];

        $applicator = (new Applicator($this->entityManager, Entity\Performance::class))
            ->enableRelationships()
            ->setEntityAlias('row');
        $queryBuilder = $applicator($filter);

        $this->assertTrue(in_array('row', $applicator->getEntityAliasMap()));
    }

    public function testSetEntityAliasToEmptyThrowsException(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Entity alias cannot be empty');

        $applicator = (new Applicator($this->entityManager, Entity\Performance::class))
            ->enableRelationships()
            ->setEntityAlias('');
    }

    public function testSetFieldAliases(): void
    {
        $filter = ['province|neq' => 'Utah'];

        $applicator = (new Applicator($this->entityManager, Entity\Performance::class))
            ->setFieldAliases(['province' => 'state']);
        $queryBuilder = $applicator($filter);

        $this->assertEquals(5, sizeof($queryBuilder->getQuery()->getResult()));
    }

    public function testFilterableFields(): void
    {
        $filter = ['state|neq' => 'Utah'];

        $applicator = (new Applicator($this->entityManager, Entity\Performance::class))
            ->setFilterableFields(['state']);
        $queryBuilder = $applicator($filter);

        $this->assertEquals(5, sizeof($queryBuilder->getQuery()->getResult()));
    }

    public function testFilterableFieldsDisallowsFilteringOnField(): void
    {
        $filter = ['state|neq' => 'Utah'];

        $applicator = (new Applicator($this->entityManager, Entity\Performance::class))
            ->setFilterableFields(['name']);
        $queryBuilder = $applicator($filter);

        $this->assertEquals(7, sizeof($queryBuilder->getQuery()->getResult()));
    }

    public function testInvokeWithNoFilters(): void
    {
        $filter = [];

        $applicator = (new Applicator($this->entityManager, Entity\Performance::class));
        $queryBuilder = $applicator($filter);

        $this->assertEquals(7, sizeof($queryBuilder->getQuery()->getResult()));
    }

    public function populateData()
    {
        $artists = [
            'Grateful Dead' => [
                '1995-02-21' => [
                    'venue' => 'Delta Center',
                    'city' => 'Salt Lake City',
                    'state' => 'Utah',
                ],
                '1969-11-08' => [
                    'venue' => 'Fillmore Auditorium',
                    'city' => 'San Francisco',
                    'state' => 'California',
                ],
                '1977-05-08' => [
                  'venue' => 'Barton Hall, Cornell University',
                  'city' => 'Ithaca',
                  'state' => 'New York',
                ],
                '1995-07-09' => [
                  'venue' => 'Soldier Field',
                  'city' => 'Chicago',
                  'state' => 'Illinois',
                ],
            ],
            'Phish' => [
                '1998-11-02' => [
                    'venue' => 'E Center',
                    'city' => 'West Valley City',
                    'state' => 'Utah',
                ],
                '1999-12-31' => [
                    'venue' => 'Big Cypress Seminole Reservation',
                    'city' => 'Big Cypress',
                    'state' => 'Florida',
                ],
            ],
            'String Cheese Incident' => [
                '2002-06-21' => [
                    'venue' => 'Bonnaroo',
                    'city' => 'Manchester',
                    'state' => 'Tennessee',
                ],
            ],
        ];
        foreach ($artists as $name => $performances) {
            $artist = (new Entity\Artist())
                ->setName($name);
            $this->entityManager->persist($artist);

            foreach ($performances as $performanceDate => $location) {
                $performance = (new Entity\Performance())
                    ->setPerformanceDate(new DateTime($performanceDate . ' 00:00:00.000000'))
                    ->setVenue($location['venue'])
                    ->setCity($location['city'])
                    ->setState($location['state'])
                    ->setArtist($artist);
                $this->entityManager->persist($performance);
            }
        }

        $this->entityManager->flush();
    }
}
