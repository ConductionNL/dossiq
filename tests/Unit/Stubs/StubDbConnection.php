<?php

/**
 * Stub DB Connection for Unit Tests
 *
 * Provides a minimal stub for use in unit tests without a real Nextcloud
 * installation. Does not actually implement OCP\IDBConnection (which
 * requires Doctrine DBAL) — use as a dependency injection replacement
 * via type-hint widening at call sites.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Stubs;

/**
 * Minimal stub that mimics enough of IDBConnection for KpiAggregationService tests.
 *
 * PHP cannot implement OCP\IDBConnection here because IDBConnection references
 * Doctrine DBAL types (Schema, etc.) not present in this repo. Instead we
 * provide a duck-typed stub that satisfies the calls made by the service.
 *
 * KpiAggregationService only calls getQueryBuilder() on the connection, so that
 * is the only method that needs a real implementation.
 */
class StubDbConnection
{

    /**
     * The query builder stub to return.
     *
     * @var StubQueryBuilder
     */
    private StubQueryBuilder $qb;

    /**
     * Number of times getQueryBuilder() has been called.
     *
     * @var int
     */
    public int $getQueryBuilderCallCount = 0;

    /**
     * Whether to throw on getQueryBuilder().
     *
     * @var bool
     */
    private bool $fail;


    /**
     * Constructor.
     *
     * @param StubDbResult $result The result to return from queries
     * @param bool         $fail   Whether to throw on getQueryBuilder()
     *
     * @return void
     */
    public function __construct(StubDbResult $result, bool $fail = false)
    {
        $this->fail = $fail;
        $this->qb   = new StubQueryBuilder($result);
    }//end __construct()


    /**
     * Return the stub query builder.
     *
     * @return StubQueryBuilder The stub query builder
     *
     * @throws \RuntimeException When $fail is true
     */
    public function getQueryBuilder(): StubQueryBuilder
    {
        $this->getQueryBuilderCallCount++;

        if ($this->fail === true) {
            throw new \RuntimeException('DB not available');
        }

        return $this->qb;
    }//end getQueryBuilder()


}//end class
