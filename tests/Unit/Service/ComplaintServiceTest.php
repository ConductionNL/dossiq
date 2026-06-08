<?php

/**
 * ComplaintService Unit Tests
 *
 * Tests for complaint lifecycle, Awb deadline calculation, working-day math,
 * verdaging logic, and status transitions.
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
 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-02
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\ComplaintService;
use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Typed stub for the OpenRegister ObjectService.
 *
 * ComplaintService calls ObjectService::saveObject() with named arguments
 * (object:/register:/schema:/uuid:). A bare addMethods() magic mock rejects
 * named arguments with "Unknown named parameter"; this typed interface lets
 * PHPUnit generate a mock whose signature accepts them.
 */
interface ComplaintObjectServiceStub
{
    /**
     * Find a single object by ID.
     *
     * @param string $register Register slug
     * @param string $schema   Schema slug
     * @param string $id       Object UUID
     *
     * @return array<string,mixed>|null
     */
    public function findObject(string $register, string $schema, string $id): ?array;

    /**
     * Save or update an object.
     *
     * @param array<string,mixed> $object   Object data
     * @param string              $register Register slug
     * @param string              $schema   Schema slug
     * @param string|null         $uuid     Optional object UUID for updates
     *
     * @return array<string,mixed>
     */
    public function saveObject(array $object, string $register, string $schema, ?string $uuid=null): array;
}//end interface

/**
 * Unit tests for ComplaintService.
 *
 * @covers \OCA\Procest\Service\ComplaintService
 */
class ComplaintServiceTest extends TestCase
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
     * @var ComplaintService
     */
    private ComplaintService $service;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        $this->service = new ComplaintService(
            settingsService: $this->settingsService,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * Awb: 5 working days from 2026-03-01 (Monday) = 2026-03-08 (Monday after weekend skip).
     *
     * @return void
     */
    public function testAddWorkingDaysSkipsWeekend(): void
    {
        // 2026-03-01 is a Sunday => treat as Monday 2026-03-02 => +5 WD = 2026-03-09.
        // Let's use 2026-03-02 (Monday): +5 WD should yield 2026-03-09 (Monday).
        $result = $this->service->addWorkingDays('2026-03-02', 5);
        $this->assertSame('2026-03-09', $result);
    }//end testAddWorkingDaysSkipsWeekend()

    /**
     * Awb: working-day calculator skips Saturday and Sunday correctly.
     *
     * @return void
     */
    public function testAddWorkingDaysSkipsBothWeekendDays(): void
    {
        // 2026-04-30 (Thursday) + 2 WD = skips weekend => 2026-05-04 (Monday).
        $result = $this->service->addWorkingDays('2026-04-30', 2);
        $this->assertSame('2026-05-04', $result);
    }//end testAddWorkingDaysSkipsBothWeekendDays()

    /**
     * Awb: working-day calculator skips Nieuwjaarsdag (01-01).
     *
     * @return void
     */
    public function testAddWorkingDaysSkipsNieuwjaarsdag(): void
    {
        // 2026-12-31 (Thursday) + 1 WD: Jan 1 is holiday, Jan 2 Saturday, Jan 3 Sunday → result = 2027-01-04.
        $result = $this->service->addWorkingDays('2026-12-31', 1);
        $this->assertSame('2027-01-04', $result);
    }//end testAddWorkingDaysSkipsNieuwjaarsdag()

    /**
     * Awb: isWorkingDay returns false for Saturday.
     *
     * @return void
     */
    public function testIsWorkingDayReturnsFalseForSaturday(): void
    {
        $saturday = new \DateTimeImmutable('2026-03-07'); // Saturday
        $this->assertFalse($this->service->isWorkingDay($saturday));
    }//end testIsWorkingDayReturnsFalseForSaturday()

    /**
     * Awb: isWorkingDay returns false for Sunday.
     *
     * @return void
     */
    public function testIsWorkingDayReturnsFalseForSunday(): void
    {
        $sunday = new \DateTimeImmutable('2026-03-08'); // Sunday
        $this->assertFalse($this->service->isWorkingDay($sunday));
    }//end testIsWorkingDayReturnsFalseForSunday()

    /**
     * Awb: isWorkingDay returns true for a regular Monday.
     *
     * @return void
     */
    public function testIsWorkingDayReturnsTrueForMonday(): void
    {
        $monday = new \DateTimeImmutable('2026-03-09'); // Monday
        $this->assertTrue($this->service->isWorkingDay($monday));
    }//end testIsWorkingDayReturnsTrueForMonday()

    /**
     * Awb: isWorkingDay returns false for Koningsdag (04-27).
     *
     * @return void
     */
    public function testIsWorkingDayReturnsFalseForKoningsdag(): void
    {
        $koningsdag = new \DateTimeImmutable('2026-04-27'); // Koningsdag (Monday)
        $this->assertFalse($this->service->isWorkingDay($koningsdag));
    }//end testIsWorkingDayReturnsFalseForKoningsdag()

    /**
     * Awb: isWorkingDay returns false for Eerste Kerstdag (12-25).
     *
     * @return void
     */
    public function testIsWorkingDayReturnsFalseForKerstdag(): void
    {
        $kerstdag = new \DateTimeImmutable('2026-12-25');
        $this->assertFalse($this->service->isWorkingDay($kerstdag));
    }//end testIsWorkingDayReturnsFalseForKerstdag()

    /**
     * Awb: 6-week calendar deadline from 2026-03-01 = 2026-04-12.
     *
     * @return void
     */
    public function testAddCalendarWeeksProducesCorrectDeadline(): void
    {
        $result = $this->service->addCalendarWeeks('2026-03-01', 6);
        $this->assertSame('2026-04-12', $result);
    }//end testAddCalendarWeeksProducesCorrectDeadline()

    /**
     * Verdaging: adds 4 weeks to the existing afhandelDeadline and sets verdagingMogelijk=false.
     *
     * @return void
     */
    public function testRequestVerdagingUpdatesDeadlineAndSetsFlag(): void
    {
        $complaint = [
            'verdagingMogelijk' => true,
            'afhandelDeadline'  => '2026-04-12',
        ];

        $objectServiceMock = $this->createMock(ComplaintObjectServiceStub::class);

        $this->settingsService
            ->method('getObjectService')
            ->willReturn($objectServiceMock);

        $this->settingsService
            ->method('getConfigValue')
            ->willReturnMap([
                ['register', '', 'procest'],
                ['complaint_schema', '', 'complaint'],
            ]);

        // The service calls findObject to retrieve the complaint.
        $objectServiceMock
            ->method('findObject')
            ->willReturn($complaint);

        // saveObject receives the updated data.
        $objectServiceMock
            ->method('saveObject')
            ->willReturnCallback(
                function (array $data, string $reg, string $sch, ?string $id=null) {
                    $this->assertFalse($data['verdagingMogelijk']);
                    $this->assertSame('2026-05-10', $data['afhandelDeadline']);
                    return $data;
                }
            );

        $result = $this->service->requestVerdaging('uuid-123', 'Complexe zaak vereist extra onderzoek');
        $this->assertFalse($result['verdagingMogelijk']);
    }//end testRequestVerdagingUpdatesDeadlineAndSetsFlag()

    /**
     * Verdaging: throws when verdagingMogelijk is already false.
     *
     * @return void
     */
    public function testRequestVerdagingThrowsWhenExtensionNotAvailable(): void
    {
        $complaint = ['verdagingMogelijk' => false, 'afhandelDeadline' => '2026-04-12'];

        $objectServiceMock = $this->createMock(ComplaintObjectServiceStub::class);
        $this->settingsService->method('getObjectService')->willReturn($objectServiceMock);
        $this->settingsService->method('getConfigValue')->willReturn('procest');
        $objectServiceMock->method('findObject')->willReturn($complaint);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not available/i');

        $this->service->requestVerdaging('uuid-123', 'justificatie');
    }//end testRequestVerdagingThrowsWhenExtensionNotAvailable()

    /**
     * Verdaging: throws when justificatie is empty.
     *
     * @return void
     */
    public function testRequestVerdagingThrowsWhenJustificatieEmpty(): void
    {
        $complaint = ['verdagingMogelijk' => true, 'afhandelDeadline' => '2026-04-12'];

        $objectServiceMock = $this->createMock(ComplaintObjectServiceStub::class);
        $this->settingsService->method('getObjectService')->willReturn($objectServiceMock);
        $this->settingsService->method('getConfigValue')->willReturn('procest');
        $objectServiceMock->method('findObject')->willReturn($complaint);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Justificatie/i');

        $this->service->requestVerdaging('uuid-123', '');
    }//end testRequestVerdagingThrowsWhenJustificatieEmpty()

    /**
     * Status transition: valid transition from ontvangen to ontvangst_bevestigd succeeds.
     *
     * @return void
     */
    public function testTransitionStatusSucceedsForValidTransition(): void
    {
        $complaint = ['status' => 'ontvangen'];

        $objectServiceMock = $this->createMock(ComplaintObjectServiceStub::class);
        $this->settingsService->method('getObjectService')->willReturn($objectServiceMock);
        $this->settingsService->method('getConfigValue')->willReturn('procest');
        $objectServiceMock->method('findObject')->willReturn($complaint);
        $objectServiceMock->method('saveObject')->willReturn(['status' => 'ontvangst_bevestigd']);

        $result = $this->service->transitionStatus('uuid-123', 'ontvangst_bevestigd');
        $this->assertSame('ontvangst_bevestigd', $result['status']);
    }//end testTransitionStatusSucceedsForValidTransition()

    /**
     * Status transition: invalid transition throws RuntimeException.
     *
     * @return void
     */
    public function testTransitionStatusThrowsForInvalidTransition(): void
    {
        $complaint = ['status' => 'ontvangen'];

        $objectServiceMock = $this->createMock(ComplaintObjectServiceStub::class);
        $this->settingsService->method('getObjectService')->willReturn($objectServiceMock);
        $this->settingsService->method('getConfigValue')->willReturn('procest');
        $objectServiceMock->method('findObject')->willReturn($complaint);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not allowed/i');

        $this->service->transitionStatus('uuid-123', 'afgehandeld');
    }//end testTransitionStatusThrowsForInvalidTransition()

    /**
     * createComplaint: throws when required fields are missing.
     *
     * @return void
     */
    public function testCreateComplaintThrowsWhenRequiredFieldsMissing(): void
    {
        $this->settingsService->method('getObjectService')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Required fields/i');

        $this->service->createComplaint(['behandelaar' => 'user1']);
    }//end testCreateComplaintThrowsWhenRequiredFieldsMissing()

    /**
     * createComplaint: throws when OpenRegister is not available.
     *
     * @return void
     */
    public function testCreateComplaintThrowsWhenOpenRegisterUnavailable(): void
    {
        $this->settingsService->method('getObjectService')->willReturn(null);

        $this->expectException(\RuntimeException::class);

        // This will throw "Required fields missing" before OpenRegister check
        // for onderwerp/omschrijving/ontvangstdatum; pass them all.
        $this->service->createComplaint([
            'onderwerp'       => 'Test',
            'omschrijving'    => 'Description',
            'ontvangstdatum'  => '2026-03-01',
        ]);
    }//end testCreateComplaintThrowsWhenOpenRegisterUnavailable()

}//end class
