<?php

/**
 * Tests for ReportingController
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
use OCA\Procest\Controller\ReportingController;
use OCA\Procest\Service\ReportingService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test case for ReportingController
 *
 * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-21
 */
class ReportingControllerTest extends TestCase
{

    private ReportingController $controller;
    private IRequest $request;
    private ReportingService $reportingService;
    private LoggerInterface $logger;


    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->reportingService = $this->createMock(ReportingService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new ReportingController(
            Application::APP_ID,
            $this->request,
            $this->reportingService,
            $this->logger
        );
    }


    /**
     * Test getting a filtered report.
     *
     * @return void
     */
    public function testGetReport(): void
    {
        $expectedReport = [
            'title' => 'Doorlooptijd Management Report',
            'generatedAt' => date('Y-m-d\TH:i:s'),
            'filters' => [],
            'summary' => [
                'totalCases' => 100,
                'slaAdherence' => ['percentage' => 87.5],
            ],
            'data' => [],
        ];

        $this->reportingService
            ->expects($this->once())
            ->method('generateReport')
            ->willReturn($expectedReport);

        $this->reportingService
            ->expects($this->once())
            ->method('applyFilters')
            ->willReturn([]);

        $response = $this->controller->getReport();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }


    /**
     * Test getting a report with filters applied.
     *
     * @return void
     */
    public function testGetReportWithFilters(): void
    {
        $expectedReport = [
            'title' => 'Doorlooptijd Management Report',
            'generatedAt' => date('Y-m-d\TH:i:s'),
            'filters' => [
                'caseType' => 'bezwaarschrift',
            ],
            'summary' => [
                'totalCases' => 50,
            ],
            'data' => [],
        ];

        $this->reportingService
            ->expects($this->once())
            ->method('generateReport')
            ->willReturn($expectedReport);

        $this->reportingService
            ->expects($this->once())
            ->method('applyFilters')
            ->willReturn([]);

        $response = $this->controller->getReport(caseType: 'bezwaarschrift');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }


    /**
     * Test exporting report as CSV.
     *
     * @return void
     */
    public function testExportAsCsv(): void
    {
        $expectedReport = [
            'title' => 'Doorlooptijd Management Report',
            'generatedAt' => date('Y-m-d\TH:i:s'),
            'filters' => [],
            'summary' => [],
            'data' => [],
        ];

        $this->reportingService
            ->expects($this->once())
            ->method('generateReport')
            ->willReturn($expectedReport);

        $this->reportingService
            ->expects($this->once())
            ->method('prepareExportData')
            ->willReturn([
                'metadata' => [],
                'summary' => [],
                'caseData' => [],
                'csvHeaders' => [],
                'format' => 'csv',
            ]);

        $response = $this->controller->export(format: 'csv');

        $this->assertNotNull($response);
    }


    /**
     * Test export with invalid format.
     *

     * @return void
     */
    public function testExportWithInvalidFormat(): void
    {
        $response = $this->controller->export(format: 'invalid');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());
    }


    /**
     * Test getting filter options.
     *
     * @return void
     */
    public function testGetFilterOptions(): void
    {
        $expectedOptions = [
            'caseTypes' => [
                'bezwaarschrift' => 'Bezwaarschrift',
            ],
            'teams' => [
                'team-a' => 'Team A',
            ],
            'statuses' => [
                'completed' => 'Completed',
            ],
        ];

        $this->reportingService
            ->expects($this->once())
            ->method('getFilterOptions')
            ->willReturn($expectedOptions);

        $response = $this->controller->getFilterOptions();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }
}
