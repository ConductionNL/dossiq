<?php

/**
 * ZgwPaginationHelper Unit Tests
 *
 * Tests for the ZGW pagination wrapping logic.
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

use OCA\Procest\Service\ZgwPaginationHelper;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the ZgwPaginationHelper class.
 *
 * @covers \OCA\Procest\Service\ZgwPaginationHelper
 */
class ZgwPaginationHelperTest extends TestCase
{

    /**
     * The service under test.
     *
     * @var ZgwPaginationHelper
     */
    private ZgwPaginationHelper $helper;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->helper = new ZgwPaginationHelper();

    }//end setUp()


    /**
     * Test single page result has no next or previous links.
     *
     * @return void
     */
    public function testSinglePageHasNoNextOrPrevious(): void
    {
        $result = $this->helper->wrapResults(
            mappedObjects: [['id' => '1'], ['id' => '2']],
            totalCount: 2,
            page: 1,
            pageSize: 10,
            baseUrl: 'http://example.com/api/zaken',
            queryParams: []
        );

        $this->assertSame(2, $result['count']);
        $this->assertNull($result['next']);
        $this->assertNull($result['previous']);
        $this->assertCount(2, $result['results']);

    }//end testSinglePageHasNoNextOrPrevious()


    /**
     * Test first page of multiple pages has next but no previous.
     *
     * @return void
     */
    public function testFirstPageHasNextButNoPrevious(): void
    {
        $result = $this->helper->wrapResults(
            mappedObjects: [['id' => '1']],
            totalCount: 25,
            page: 1,
            pageSize: 10,
            baseUrl: 'http://example.com/api/zaken',
            queryParams: []
        );

        $this->assertSame(25, $result['count']);
        $this->assertNotNull($result['next']);
        $this->assertStringContainsString('page=2', $result['next']);
        $this->assertNull($result['previous']);

    }//end testFirstPageHasNextButNoPrevious()


    /**
     * Test middle page has both next and previous.
     *
     * @return void
     */
    public function testMiddlePageHasBothNextAndPrevious(): void
    {
        $result = $this->helper->wrapResults(
            mappedObjects: [['id' => '5']],
            totalCount: 30,
            page: 2,
            pageSize: 10,
            baseUrl: 'http://example.com/api/zaken',
            queryParams: []
        );

        $this->assertSame(30, $result['count']);
        $this->assertNotNull($result['next']);
        $this->assertStringContainsString('page=3', $result['next']);
        $this->assertNotNull($result['previous']);
        $this->assertStringContainsString('page=1', $result['previous']);

    }//end testMiddlePageHasBothNextAndPrevious()


    /**
     * Test last page has previous but no next.
     *
     * @return void
     */
    public function testLastPageHasPreviousButNoNext(): void
    {
        $result = $this->helper->wrapResults(
            mappedObjects: [['id' => '10']],
            totalCount: 30,
            page: 3,
            pageSize: 10,
            baseUrl: 'http://example.com/api/zaken',
            queryParams: []
        );

        $this->assertSame(30, $result['count']);
        $this->assertNull($result['next']);
        $this->assertNotNull($result['previous']);
        $this->assertStringContainsString('page=2', $result['previous']);

    }//end testLastPageHasPreviousButNoNext()


    /**
     * Test that framework params are filtered from query string.
     *
     * @return void
     */
    public function testFrameworkParamsFiltered(): void
    {
        $result = $this->helper->wrapResults(
            mappedObjects: [],
            totalCount: 20,
            page: 1,
            pageSize: 10,
            baseUrl: 'http://example.com/api/zaken',
            queryParams: [
                'status'   => 'open',
                '_route'   => 'procest.zrc.index',
                'zgwApi'   => 'zaken',
                'resource' => 'zaken',
            ]
        );

        $this->assertNotNull($result['next']);
        $this->assertStringContainsString('status=open', $result['next']);
        $this->assertStringNotContainsString('_route', $result['next']);
        $this->assertStringNotContainsString('zgwApi', $result['next']);
        $this->assertStringNotContainsString('resource', $result['next']);

    }//end testFrameworkParamsFiltered()


    /**
     * Test empty results.
     *
     * @return void
     */
    public function testEmptyResults(): void
    {
        $result = $this->helper->wrapResults(
            mappedObjects: [],
            totalCount: 0,
            page: 1,
            pageSize: 10,
            baseUrl: 'http://example.com/api/zaken',
            queryParams: []
        );

        $this->assertSame(0, $result['count']);
        $this->assertNull($result['next']);
        $this->assertNull($result['previous']);
        $this->assertCount(0, $result['results']);

    }//end testEmptyResults()


    /**
     * Test zero page size does not cause division by zero.
     *
     * @return void
     */
    public function testZeroPageSizeNoDivisionByZero(): void
    {
        $result = $this->helper->wrapResults(
            mappedObjects: [['id' => '1']],
            totalCount: 5,
            page: 1,
            pageSize: 0,
            baseUrl: 'http://example.com/api/zaken',
            queryParams: []
        );

        $this->assertSame(5, $result['count']);
        $this->assertCount(1, $result['results']);

    }//end testZeroPageSizeNoDivisionByZero()


}//end class
