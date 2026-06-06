<?php

/**
 * ComplaintAnalyticsService Unit Tests
 *
 * Tests for frequency aggregation, systemic-issue detection,
 * employee-threshold alerts, and KPI summary.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-05
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\ComplaintAnalyticsService;
use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for ComplaintAnalyticsService.
 *
 * @covers \OCA\Procest\Service\ComplaintAnalyticsService
 */
class ComplaintAnalyticsServiceTest extends TestCase
{

    /**
     * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
     */
    private SettingsService $settingsService;

    /**
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private LoggerInterface $logger;

    /**
     * @var ComplaintAnalyticsService
     */
    private ComplaintAnalyticsService $service;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        $this->service = new ComplaintAnalyticsService(
            settingsService: $this->settingsService,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * getFrequencyByDimension: returns empty array when OpenRegister unavailable.
     *
     * @return void
     */
    public function testGetFrequencyByDimensionReturnsEmptyWhenUnavailable(): void
    {
        $this->settingsService->method('getObjectService')->willReturn(null);

        $result = $this->service->getFrequencyByDimension('categorie', '2026-01-01', '2026-12-31');
        $this->assertSame([], $result);
    }//end testGetFrequencyByDimensionReturnsEmptyWhenUnavailable()

    /**
     * getFrequencyByDimension: groups complaints correctly by categorie.
     *
     * @return void
     */
    public function testGetFrequencyByDimensionGroupsByCategorie(): void
    {
        $complaints = [
            ['categorie' => 'Bejegening', 'ontvangstdatum' => '2026-03-01'],
            ['categorie' => 'Bejegening', 'ontvangstdatum' => '2026-03-15'],
            ['categorie' => 'Wachttijd', 'ontvangstdatum'  => '2026-03-10'],
        ];

        $objectServiceMock = $this->getMockBuilder(\stdClass::class)->addMethods(['findObjects', 'findObject', 'saveObject'])->getMock();
        $this->settingsService->method('getObjectService')->willReturn($objectServiceMock);
        $this->settingsService->method('getConfigValue')->willReturn('procest');
        $objectServiceMock->method('findObjects')->willReturn($complaints);

        $result = $this->service->getFrequencyByDimension('categorie', '2026-01-01', '2026-12-31');

        $this->assertSame(2, $result['Bejegening']);
        $this->assertSame(1, $result['Wachttijd']);
    }//end testGetFrequencyByDimensionGroupsByCategorie()

    /**
     * getMonthlyTrend: produces correct month groupings.
     *
     * @return void
     */
    public function testGetMonthlyTrendGroupsByMonth(): void
    {
        $complaints = [
            ['ontvangstdatum' => '2026-01-05'],
            ['ontvangstdatum' => '2026-01-20'],
            ['ontvangstdatum' => '2026-02-10'],
        ];

        $objectServiceMock = $this->getMockBuilder(\stdClass::class)->addMethods(['findObjects', 'findObject', 'saveObject'])->getMock();
        $this->settingsService->method('getObjectService')->willReturn($objectServiceMock);
        $this->settingsService->method('getConfigValue')->willReturn('procest');
        $objectServiceMock->method('findObjects')->willReturn($complaints);

        $result = $this->service->getMonthlyTrend('2026-01-01', '2026-12-31');

        $this->assertSame(2, $result['2026-01']);
        $this->assertSame(1, $result['2026-02']);
    }//end testGetMonthlyTrendGroupsByMonth()

    /**
     * detectSystemicIssues: flags categories with >50% QoQ increase.
     *
     * @return void
     */
    public function testDetectSystemicIssuesFlagsHighIncreaseCategories(): void
    {
        $objectServiceMock = $this->getMockBuilder(\stdClass::class)->addMethods(['findObjects', 'findObject', 'saveObject'])->getMock();
        $this->settingsService->method('getObjectService')->willReturn($objectServiceMock);
        $this->settingsService->method('getConfigValue')->willReturn('procest');

        // Q1 2026 has 6 Wachttijd complaints, Q4 2025 had 3 => 100% increase.
        $currentComplaints = array_fill(0, 6, ['categorie' => 'Wachttijd', 'ontvangstdatum' => '2026-01-15']);
        $prevComplaints    = array_fill(0, 3, ['categorie' => 'Wachttijd', 'ontvangstdatum' => '2025-10-15']);

        $callCount = 0;
        $objectServiceMock
            ->method('findObjects')
            ->willReturnCallback(
                function () use (&$callCount, $currentComplaints, $prevComplaints) {
                    $callCount++;
                    return $callCount === 1 ? $currentComplaints : $prevComplaints;
                }
            );

        $result = $this->service->detectSystemicIssues(2026, 1);

        $this->assertNotEmpty($result);
        $this->assertSame('Wachttijd', $result[0]['categorie']);
        $this->assertGreaterThan(50, $result[0]['increasePercent']);
    }//end testDetectSystemicIssuesFlagsHighIncreaseCategories()

    /**
     * detectSystemicIssues: does not flag categories below 50% threshold.
     *
     * @return void
     */
    public function testDetectSystemicIssuesDoesNotFlagBelowThreshold(): void
    {
        $objectServiceMock = $this->getMockBuilder(\stdClass::class)->addMethods(['findObjects', 'findObject', 'saveObject'])->getMock();
        $this->settingsService->method('getObjectService')->willReturn($objectServiceMock);
        $this->settingsService->method('getConfigValue')->willReturn('procest');

        // 10 current, 8 previous => 25% increase — below threshold.
        $currentComplaints = array_fill(0, 10, ['categorie' => 'Dienstverlening', 'ontvangstdatum' => '2026-01-10']);
        $prevComplaints    = array_fill(0, 8, ['categorie' => 'Dienstverlening', 'ontvangstdatum' => '2025-10-10']);

        $callCount = 0;
        $objectServiceMock
            ->method('findObjects')
            ->willReturnCallback(
                function () use (&$callCount, $currentComplaints, $prevComplaints) {
                    $callCount++;
                    return $callCount === 1 ? $currentComplaints : $prevComplaints;
                }
            );

        $result = $this->service->detectSystemicIssues(2026, 1);
        $this->assertEmpty($result);
    }//end testDetectSystemicIssuesDoesNotFlagBelowThreshold()

    /**
     * checkEmployeeThresholdAlerts: returns anonymized alerts when threshold exceeded.
     *
     * @return void
     */
    public function testCheckEmployeeThresholdAlertsReturnsAlertsAboveThreshold(): void
    {
        $objectServiceMock = $this->getMockBuilder(\stdClass::class)->addMethods(['findObjects', 'findObject', 'saveObject'])->getMock();
        $this->settingsService->method('getObjectService')->willReturn($objectServiceMock);
        $this->settingsService->method('getConfigValue')->willReturn('procest');

        $complaints = [
            ['betrokkenMedewerker' => 'medewerker-A', 'categorie' => 'Bejegening', 'ontvangstdatum' => '2026-01-10'],
            ['betrokkenMedewerker' => 'medewerker-A', 'categorie' => 'Bejegening', 'ontvangstdatum' => '2026-02-10'],
            ['betrokkenMedewerker' => 'medewerker-A', 'categorie' => 'Wachttijd', 'ontvangstdatum'  => '2026-03-10'],
        ];

        $objectServiceMock->method('findObjects')->willReturn($complaints);

        $alerts = $this->service->checkEmployeeThresholdAlerts();

        // Alert should be fired but must NOT contain the employee ID.
        $this->assertNotEmpty($alerts);
        $this->assertSame(3, $alerts[0]['count']);
        foreach ($alerts as $alert) {
            $this->assertArrayNotHasKey('betrokkenMedewerker', $alert);
        }
    }//end testCheckEmployeeThresholdAlertsReturnsAlertsAboveThreshold()

    /**
     * checkEmployeeThresholdAlerts: returns empty when below threshold.
     *
     * @return void
     */
    public function testCheckEmployeeThresholdAlertsReturnsEmptyBelowThreshold(): void
    {
        $objectServiceMock = $this->getMockBuilder(\stdClass::class)->addMethods(['findObjects', 'findObject', 'saveObject'])->getMock();
        $this->settingsService->method('getObjectService')->willReturn($objectServiceMock);
        $this->settingsService->method('getConfigValue')->willReturn('procest');

        $complaints = [
            ['betrokkenMedewerker' => 'medewerker-B', 'categorie' => 'Wachttijd', 'ontvangstdatum' => '2026-01-10'],
            ['betrokkenMedewerker' => 'medewerker-B', 'categorie' => 'Wachttijd', 'ontvangstdatum' => '2026-02-10'],
        ];

        $objectServiceMock->method('findObjects')->willReturn($complaints);

        $alerts = $this->service->checkEmployeeThresholdAlerts();
        $this->assertEmpty($alerts);
    }//end testCheckEmployeeThresholdAlertsReturnsEmptyBelowThreshold()

    /**
     * getKpiSummary: returns expected structure.
     *
     * @return void
     */
    public function testGetKpiSummaryReturnsExpectedStructure(): void
    {
        $objectServiceMock = $this->getMockBuilder(\stdClass::class)->addMethods(['findObjects', 'findObject', 'saveObject'])->getMock();
        $this->settingsService->method('getObjectService')->willReturn($objectServiceMock);
        $this->settingsService->method('getConfigValue')->willReturn('procest');

        $complaints = [
            ['status' => 'afgehandeld', 'afhandelDeadline' => '2026-04-12', 'ontvangstdatum' => '2026-03-01'],
            ['status' => 'in_behandeling', 'ontvangstdatum' => '2026-03-15'],
        ];

        $objectServiceMock->method('findObjects')->willReturn($complaints);

        $kpi = $this->service->getKpiSummary('2026-03-01', '2026-03-31');

        $this->assertArrayHasKey('total', $kpi);
        $this->assertArrayHasKey('resolved', $kpi);
        $this->assertArrayHasKey('awbComplianceRate', $kpi);
        $this->assertSame(2, $kpi['total']);
        $this->assertSame(1, $kpi['resolved']);
    }//end testGetKpiSummaryReturnsExpectedStructure()

}//end class
