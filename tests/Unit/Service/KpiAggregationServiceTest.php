<?php

/**
 * KpiAggregationService Unit Tests
 *
 * Tests for the Procest KpiAggregationService that computes dashboard
 * KPI metrics via DB-side aggregation queries.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
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

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\KpiAggregationService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for KpiAggregationService.
 *
 * Depends on the Doctrine DBAL stubs loaded in tests/bootstrap.php to allow
 * mocking of OCP\IDBConnection and OCP\DB\QueryBuilder\IQueryBuilder.
 *
 * @covers \OCA\Procest\Service\KpiAggregationService
 */
class KpiAggregationServiceTest extends TestCase
{

    /**
     * The mocked database connection.
     *
     * @var IDBConnection|MockObject
     */
    private IDBConnection $db;

    /**
     * The mocked logger.
     *
     * @var LoggerInterface|MockObject
     */
    private LoggerInterface $logger;

    /**
     * The service under test.
     *
     * @var KpiAggregationService
     */
    private KpiAggregationService $service;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->db     = $this->createMock(IDBConnection::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new KpiAggregationService(
            $this->db,
            $this->logger,
        );
    }//end setUp()


    /**
     * Build a query builder mock that returns a count result.
     *
     * @param array<string, mixed>|false $singleRow The row for fetch()
     * @param array<array<string,mixed>> $allRows   The rows for fetchAll()
     *
     * @return IQueryBuilder|MockObject
     */
    private function buildQbMock(array|false $singleRow = false, array $allRows = []): IQueryBuilder
    {
        $resultMock = $this->createMock(IResult::class);
        $resultMock->method('fetch')->willReturn($singleRow);
        $resultMock->method('fetchAll')->willReturn($allRows);
        $resultMock->method('closeCursor');

        $compositeMock = $this->createMock(ICompositeExpression::class);
        $compositeMock->method('addMultiple')->willReturnSelf();
        $compositeMock->method('add')->willReturnSelf();
        $compositeMock->method('count')->willReturn(1);
        $compositeMock->method('getType')->willReturn('OR');

        $exprMock = $this->createMock(IExpressionBuilder::class);
        $exprMock->method('eq')->willReturn('1=1');
        $exprMock->method('like')->willReturn('1=1');
        $exprMock->method('lt')->willReturn('1=1');
        $exprMock->method('isNull')->willReturn('1=1');
        $exprMock->method('isNotNull')->willReturn('1=1');
        $exprMock->method('orX')->willReturn($compositeMock);
        $exprMock->method('andX')->willReturn($compositeMock);
        $exprMock->method('in')->willReturn('1=1');

        $queryFunctionMock = $this->createMock(IQueryFunction::class);
        $queryFunctionMock->method('__toString')->willReturn('COUNT(*)');

        $funcMock = $this->createMock(IFunctionBuilder::class);
        $funcMock->method('count')->willReturn($queryFunctionMock);

        $qbMock = $this->createMock(IQueryBuilder::class);
        $qbMock->method('select')->willReturnSelf();
        $qbMock->method('selectAlias')->willReturnSelf();
        $qbMock->method('from')->willReturnSelf();
        $qbMock->method('innerJoin')->willReturnSelf();
        $qbMock->method('where')->willReturnSelf();
        $qbMock->method('andWhere')->willReturnSelf();
        $qbMock->method('orWhere')->willReturnSelf();
        $qbMock->method('groupBy')->willReturnSelf();
        $qbMock->method('orderBy')->willReturnSelf();
        $qbMock->method('createNamedParameter')->willReturn(':p');
        $qbMock->method('createFunction')->willReturn('fn()');
        $qbMock->method('expr')->willReturn($exprMock);
        $qbMock->method('func')->willReturn($funcMock);
        $qbMock->method('executeQuery')->willReturn($resultMock);

        return $qbMock;
    }//end buildQbMock()


    /**
     * Test that computeKpis returns an array with all expected keys.
     *
     * @return void
     */
    public function testComputeKpisReturnsAllExpectedKeys(): void
    {
        $this->db->method('getQueryBuilder')->willReturn($this->buildQbMock(singleRow: ['cnt' => '5', 'avg_days' => null]));

        $result = $this->service->computeKpis(userId: 'testuser');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('openCount', $result);
        $this->assertArrayHasKey('newToday', $result);
        $this->assertArrayHasKey('overdueCount', $result);
        $this->assertArrayHasKey('completedCount', $result);
        $this->assertArrayHasKey('taskCount', $result);
        $this->assertArrayHasKey('tasksDueToday', $result);
        $this->assertArrayHasKey('statusBreakdown', $result);
        $this->assertArrayHasKey('typeBreakdown', $result);
        $this->assertArrayHasKey('avgProcessingDays', $result);
    }//end testComputeKpisReturnsAllExpectedKeys()


    /**
     * Test that all integer fields are 0 when the DB throws exceptions.
     *
     * Covers V02: zero defaults on fresh installation / empty data.
     *
     * @return void
     */
    public function testComputeKpisReturnsZeroDefaultsOnDbError(): void
    {
        $this->db->method('getQueryBuilder')->willThrowException(new \Exception('DB error'));
        $this->logger->expects($this->atLeastOnce())->method('warning');

        $result = $this->service->computeKpis(userId: 'testuser');

        $this->assertSame(0, $result['openCount']);
        $this->assertSame(0, $result['newToday']);
        $this->assertSame(0, $result['overdueCount']);
        $this->assertSame(0, $result['completedCount']);
        $this->assertSame(0, $result['taskCount']);
        $this->assertSame(0, $result['tasksDueToday']);
        $this->assertSame([], $result['statusBreakdown']);
        $this->assertSame([], $result['typeBreakdown']);
        $this->assertNull($result['avgProcessingDays']);
    }//end testComputeKpisReturnsZeroDefaultsOnDbError()


    /**
     * Test that integer fields are PHP ints (not strings).
     *
     * @return void
     */
    public function testComputeKpisReturnsTypedIntegers(): void
    {
        $this->db->method('getQueryBuilder')->willReturn($this->buildQbMock(singleRow: ['cnt' => '7', 'avg_days' => null]));

        $result = $this->service->computeKpis(userId: 'testuser');

        $this->assertIsInt($result['openCount']);
        $this->assertIsInt($result['newToday']);
        $this->assertIsInt($result['overdueCount']);
        $this->assertIsInt($result['completedCount']);
        $this->assertIsInt($result['taskCount']);
        $this->assertIsInt($result['tasksDueToday']);
        $this->assertIsArray($result['statusBreakdown']);
    }//end testComputeKpisReturnsTypedIntegers()


    /**
     * Test that statusBreakdown is an array.
     *
     * @return void
     */
    public function testStatusBreakdownIsArray(): void
    {
        $this->db->method('getQueryBuilder')->willReturn($this->buildQbMock(singleRow: ['cnt' => '0', 'avg_days' => null]));

        $result = $this->service->computeKpis(userId: 'user1');

        $this->assertIsArray($result['statusBreakdown']);
    }//end testStatusBreakdownIsArray()


    /**
     * Test that avgProcessingDays returns null when DB returns NULL.
     *
     * Covers V09: no completed cases this month path.
     *
     * @return void
     */
    public function testAvgProcessingDaysReturnsNullWhenNoData(): void
    {
        $this->db->method('getQueryBuilder')
            ->willReturn($this->buildQbMock(singleRow: ['cnt' => '0', 'avg_days' => null]));

        $result = $this->service->computeKpis(userId: 'user1');

        // avgProcessingDays must be null, not 0, when no data.
        $this->assertNull($result['avgProcessingDays']);
    }//end testAvgProcessingDaysReturnsNullWhenNoData()


    /**
     * Test that avgProcessingDays returns a float when DB returns a value.
     *
     * Covers V09: expected value 6.0 for 4 cases with durations 3/5/7/9 days.
     *
     * @return void
     */
    public function testAvgProcessingDaysReturnsCastFloat(): void
    {
        // Simulate DB returning "6.0000" (MySQL AVG format).
        $this->db->method('getQueryBuilder')
            ->willReturn($this->buildQbMock(singleRow: ['cnt' => '0', 'avg_days' => '6.0000']));

        $result = $this->service->computeKpis(userId: 'user1');

        $this->assertIsFloat($result['avgProcessingDays']);
        $this->assertEqualsWithDelta(6.0, $result['avgProcessingDays'], 0.0001);
    }//end testAvgProcessingDaysReturnsCastFloat()


    /**
     * Test that computeKpis calls getQueryBuilder 9 times (once per KPI).
     *
     * @return void
     */
    public function testComputeKpisCallsDbForEachKpi(): void
    {
        $this->db->expects($this->exactly(9))
            ->method('getQueryBuilder')
            ->willReturn($this->buildQbMock(singleRow: ['cnt' => '0', 'avg_days' => null]));

        $this->service->computeKpis(userId: 'alice');
    }//end testComputeKpisCallsDbForEachKpi()


}//end class
