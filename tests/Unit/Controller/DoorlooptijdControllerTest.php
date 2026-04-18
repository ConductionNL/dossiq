<?php

/**
 * Tests for DoorlooptijdController
 *
 * @category Test
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

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Controller\DoorlooptijdController;
use OCA\Procest\Service\BottleneckAnalysisService;
use OCA\Procest\Service\DoorlooptijdService;
use OCA\Procest\Service\TrendAnalysisService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test case for DoorlooptijdController
 *
 * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-20
 */
class DoorlooptijdControllerTest extends TestCase
{

    private DoorlooptijdController $controller;
    private IRequest $request;
    private DoorlooptijdService $doorlooptijdService;
    private BottleneckAnalysisService $bottleneckAnalysisService;
    private TrendAnalysisService $trendAnalysisService;
    private LoggerInterface $logger;


    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->doorlooptijdService = $this->createMock(DoorlooptijdService::class);
        $this->bottleneckAnalysisService = $this->createMock(BottleneckAnalysisService::class);
        $this->trendAnalysisService = $this->createMock(TrendAnalysisService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new DoorlooptijdController(
            Application::APP_ID,
            $this->request,
            $this->doorlooptijdService,
            $this->bottleneckAnalysisService,
            $this->trendAnalysisService,
            $this->logger
        );
    }


    /**
     * Test statistics endpoint.
     *
     * @return void
     */
    public function testStatistics(): void
    {
        $caseTypeId = 'test-case-type';
        $expectedStats = [
            'caseTypeId' => $caseTypeId,
            'totalCases' => 100,
            'averageDuration' => 25.5,
        ];

        $this->doorlooptijdService
            ->expects($this->once())
            ->method('getCaseTypeStatistics')
            ->willReturn($expectedStats);

        $response = $this->controller->statistics($caseTypeId);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }


    /**
     * Test bottlenecks endpoint.
     *
     * @return void
     */
    public function testBottlenecks(): void
    {
        $caseTypeId = 'test-case-type';
        $expectedAnalysis = [
            'caseTypeId' => $caseTypeId,
            'steps' => [],
        ];

        $this->bottleneckAnalysisService
            ->expects($this->once())
            ->method('analyzeBottlenecks')
            ->willReturn($expectedAnalysis);

        $response = $this->controller->bottlenecks($caseTypeId);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }


    /**
     * Test trends endpoint.
     *
     * @return void
     */
    public function testTrends(): void
    {
        $caseTypeId = 'test-case-type';
        $expectedTrend = [
            'caseTypeId' => $caseTypeId,
            'trend' => [],
        ];

        $this->trendAnalysisService
            ->expects($this->once())
            ->method('getTrend')
            ->willReturn($expectedTrend);

        $response = $this->controller->trends($caseTypeId);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }


    /**
     * Test trends endpoint with custom granularity.
     *
     * @return void
     */
    public function testTrendsWithGranularity(): void
    {
        $caseTypeId = 'test-case-type';
        $granularity = 'monthly';
        $expectedTrend = [
            'caseTypeId' => $caseTypeId,
            'granularity' => $granularity,
            'trend' => [],
        ];

        $this->trendAnalysisService
            ->expects($this->once())
            ->method('getTrend')
            ->with($caseTypeId, $this->anything(), $this->anything(), $granularity)
            ->willReturn($expectedTrend);

        $response = $this->controller->trends($caseTypeId, '', '', $granularity);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }


    /**
     * Test SLA trend endpoint.
     *
     * @return void
     */
    public function testSlaTrend(): void
    {
        $caseTypeId = 'test-case-type';
        $expectedTrend = [
            'caseTypeId' => $caseTypeId,
            'trend' => [],
        ];

        $this->trendAnalysisService
            ->expects($this->once())
            ->method('getSLATrend')
            ->willReturn($expectedTrend);

        $response = $this->controller->slaTrend($caseTypeId);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }
}
