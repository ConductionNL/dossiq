<?php

/**
 * HealthController Unit Tests
 *
 * Tests for the Procest HealthController health check endpoint.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\HealthController;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\IDBConnection;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the HealthController class.
 *
 * @spec openspec/changes/prometheus-metrics/tasks.md#task-6
 *
 * @covers \OCA\Procest\Controller\HealthController
 */
class HealthControllerTest extends TestCase
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
     * @var HealthController
     */
    private HealthController $controller;


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

        $this->controller = new HealthController(
            $this->request,
            $this->db,
            $this->appManager,
            $this->logger,
        );

    }//end setUp()


    /**
     * Test healthy system returns 200 with ok status.
     *
     * @return void
     */
    public function testHealthySystemReturnsOk(): void
    {
        $qbMock     = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $resultMock = $this->createMock(\OCP\DB\IResult::class);

        $qbMock->method('select')->willReturnSelf();
        $qbMock->method('createFunction')->willReturn('1');
        $qbMock->method('executeQuery')->willReturn($resultMock);
        $resultMock->method('closeCursor');

        $this->db->method('getQueryBuilder')->willReturn($qbMock);

        $this->appManager->method('isEnabledForUser')
            ->with('openregister')
            ->willReturn(true);

        $this->appManager->method('getAppVersion')
            ->willReturn('0.1.10');

        $response = $this->controller->index();
        $data     = $response->getData();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('ok', $data['status']);
        $this->assertSame('ok', $data['checks']['database']);
        $this->assertSame('ok', $data['checks']['openregister']);
        $this->assertSame('ok', $data['checks']['filesystem']);

    }//end testHealthySystemReturnsOk()


    /**
     * Test that OpenRegister unavailable results in error status.
     *
     * @return void
     */
    public function testOpenRegisterUnavailableReturnsError(): void
    {
        $qbMock     = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $resultMock = $this->createMock(\OCP\DB\IResult::class);

        $qbMock->method('select')->willReturnSelf();
        $qbMock->method('createFunction')->willReturn('1');
        $qbMock->method('executeQuery')->willReturn($resultMock);
        $resultMock->method('closeCursor');

        $this->db->method('getQueryBuilder')->willReturn($qbMock);

        $this->appManager->method('isEnabledForUser')
            ->with('openregister')
            ->willReturn(false);

        $this->appManager->method('getAppVersion')
            ->willReturn('0.1.10');

        $response = $this->controller->index();
        $data     = $response->getData();

        $this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
        $this->assertSame('error', $data['status']);
        $this->assertSame('ok', $data['checks']['database']);
        $this->assertSame('failed: app not enabled', $data['checks']['openregister']);

    }//end testOpenRegisterUnavailableReturnsError()


    /**
     * Test that database unreachable results in error status.
     *
     * @return void
     */
    public function testDatabaseUnreachableReturnsError(): void
    {
        $this->db->method('getQueryBuilder')
            ->willThrowException(new \Exception('Connection refused'));

        $this->appManager->method('isEnabledForUser')
            ->with('openregister')
            ->willReturn(true);

        $this->appManager->method('getAppVersion')
            ->willReturn('0.1.10');

        $response = $this->controller->index();
        $data     = $response->getData();

        $this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
        $this->assertSame('error', $data['status']);
        $this->assertStringContainsString('failed:', $data['checks']['database']);

    }//end testDatabaseUnreachableReturnsError()


    /**
     * Test that the response includes version information.
     *
     * @return void
     */
    public function testResponseIncludesVersion(): void
    {
        $this->db->method('getQueryBuilder')
            ->willThrowException(new \Exception('Not connected'));

        $this->appManager->method('isEnabledForUser')
            ->willReturn(true);

        $this->appManager->method('getAppVersion')
            ->willReturn('1.2.3');

        $response = $this->controller->index();
        $data     = $response->getData();

        $this->assertSame('1.2.3', $data['version']);

    }//end testResponseIncludesVersion()


}//end class
