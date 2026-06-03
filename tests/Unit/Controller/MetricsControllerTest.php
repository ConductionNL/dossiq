<?php

/**
 * MetricsController Unit Tests
 *
 * Tests for the Procest MetricsController Prometheus metrics endpoint.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller
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

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\MetricsController;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the MetricsController class.
 *
 * @covers \OCA\Procest\Controller\MetricsController
 */
class MetricsControllerTest extends TestCase
{

    /**
     * The mocked request.
     *
     * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
     */
    private IRequest $request;

    /**
     * The mocked database connection.
     *
     * @var IDBConnection|\PHPUnit\Framework\MockObject\MockObject
     */
    private IDBConnection $db;

    /**
     * The mocked app manager.
     *
     * @var IAppManager|\PHPUnit\Framework\MockObject\MockObject
     */
    private IAppManager $appManager;

    /**
     * The mocked logger.
     *
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private LoggerInterface $logger;

    /**
     * The controller under test.
     *
     * @var MetricsController
     */
    private MetricsController $controller;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request    = $this->createMock(IRequest::class);
        $this->db         = $this->createMock(IDBConnection::class);
        $this->appManager = $this->createMock(IAppManager::class);
        $this->logger     = $this->createMock(LoggerInterface::class);

        $this->controller = new MetricsController(
            $this->request,
            $this->db,
            $this->appManager,
            $this->logger,
        );

    }//end setUp()


    /**
     * Test that the index method returns a TextPlainResponse.
     *
     * @return void
     */
    public function testIndexReturnsTextPlainResponse(): void
    {
        // Mock the query builder to throw so queries return defaults.
        $this->db->method('getQueryBuilder')
            ->willThrowException(new \Exception('Not connected'));

        $this->appManager->method('getAppVersion')
            ->willReturn('0.1.10');

        $response = $this->controller->index();

        $this->assertSame(200, $response->getStatus());

        $headers = $response->getHeaders();
        $this->assertArrayHasKey('Content-Type', $headers);
        $this->assertSame('text/plain; version=0.0.4; charset=utf-8', $headers['Content-Type']);

    }//end testIndexReturnsTextPlainResponse()


    /**
     * Test that the metrics output contains the expected metric families.
     *
     * @return void
     */
    public function testMetricsContainsExpectedFamilies(): void
    {
        $this->db->method('getQueryBuilder')
            ->willThrowException(new \Exception('Not connected'));

        $this->appManager->method('getAppVersion')
            ->willReturn('0.1.10');

        $response = $this->controller->index();
        $content  = $response->render();

        // Verify required metric families are present.
        $this->assertStringContainsString('# HELP procest_info Application information', $content);
        $this->assertStringContainsString('# TYPE procest_info gauge', $content);
        $this->assertStringContainsString('# HELP procest_up Whether the application is healthy', $content);
        $this->assertStringContainsString('# TYPE procest_up gauge', $content);
        $this->assertStringContainsString('# HELP procest_cases_total', $content);
        $this->assertStringContainsString('# TYPE procest_cases_total gauge', $content);
        $this->assertStringContainsString('# HELP procest_cases_overdue_total', $content);
        $this->assertStringContainsString('# HELP procest_cases_created_today', $content);
        $this->assertStringContainsString('# TYPE procest_cases_created_today gauge', $content);
        $this->assertStringContainsString('# HELP procest_tasks_total', $content);
        $this->assertStringContainsString('# HELP procest_tasks_overdue_total', $content);

    }//end testMetricsContainsExpectedFamilies()


    /**
     * Test that the info gauge includes the nextcloud_version label.
     *
     * @return void
     */
    public function testInfoGaugeIncludesNextcloudVersion(): void
    {
        $this->db->method('getQueryBuilder')
            ->willThrowException(new \Exception('Not connected'));

        $this->appManager->method('getAppVersion')
            ->willReturn('0.1.10');

        $response = $this->controller->index();
        $content  = $response->render();

        // The info line should contain nextcloud_version label.
        $this->assertMatchesRegularExpression(
            '/procest_info\{.*nextcloud_version="[^"]*".*\} 1/',
            $content
        );

    }//end testInfoGaugeIncludesNextcloudVersion()


    /**
     * Test that procest_up is 0 when database is unreachable.
     *
     * @return void
     */
    public function testUpGaugeReflectsDatabaseHealth(): void
    {
        $this->db->method('getQueryBuilder')
            ->willThrowException(new \Exception('Connection refused'));

        $this->appManager->method('getAppVersion')
            ->willReturn('0.1.10');

        $response = $this->controller->index();
        $content  = $response->render();

        $this->assertStringContainsString('procest_up 0', $content);

    }//end testUpGaugeReflectsDatabaseHealth()


    /**
     * Test that the cases_created_today metric has a valid format.
     *
     * @return void
     */
    public function testCasesCreatedTodayMetricFormat(): void
    {
        $this->db->method('getQueryBuilder')
            ->willThrowException(new \Exception('Not connected'));

        $this->appManager->method('getAppVersion')
            ->willReturn('0.1.10');

        $response = $this->controller->index();
        $content  = $response->render();

        // Should have a valid integer value (0 since DB is mocked to fail).
        $this->assertStringContainsString('procest_cases_created_today 0', $content);

    }//end testCasesCreatedTodayMetricFormat()


}//end class
