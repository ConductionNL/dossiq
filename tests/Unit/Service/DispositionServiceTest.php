<?php

/**
 * DispositionService Unit Tests
 *
 * Tests for complaint disposition submission, approval, rejection, and
 * validation of oordeel values.
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
 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-04
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\DispositionService;
use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Object service stub for disposition tests (OpenRegister object-first signature).
 */
interface DispositionObjectServiceStub
{
    /**
     * Save an object (OpenRegister object-first signature).
     *
     * @param array       $object   Object data.
     * @param array       $extend   Extend parameters.
     * @param string|null $register Register id.
     * @param string|null $schema   Schema id.
     * @param string|null $uuid     Optional object uuid.
     *
     * @return array
     */
    public function saveObject(array $object, array $extend=[], ?string $register=null, ?string $schema=null, ?string $uuid=null);

    /**
     * Find objects matching a filter.
     *
     * @param string $register Register id.
     * @param string $schema   Schema id.
     * @param array  $filters  Query filters.
     *
     * @return array
     */
    public function findObjects(string $register, string $schema, array $filters=[]);
}//end interface

/**
 * Unit tests for DispositionService.
 *
 * @covers \OCA\Procest\Service\DispositionService
 */
class DispositionServiceTest extends TestCase
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
     * @var DispositionService
     */
    private DispositionService $service;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        $this->service = new DispositionService(
            settingsService: $this->settingsService,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * submitDisposition: throws when oordeel is invalid.
     *
     * @return void
     */
    public function testSubmitDispositionThrowsForInvalidOordeel(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Invalid oordeel/i');

        $this->service->submitDisposition('complaint-uuid', ['oordeel' => 'onbekend']);
    }//end testSubmitDispositionThrowsForInvalidOordeel()

    /**
     * submitDisposition: throws when gegrond oordeel has no toelichting.
     *
     * @return void
     */
    public function testSubmitDispositionThrowsWhenGegrondenMissingToelichting(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Toelichting/i');

        $this->service->submitDisposition('complaint-uuid', ['oordeel' => 'gegrond']);
    }//end testSubmitDispositionThrowsWhenGegrondenMissingToelichting()

    /**
     * submitDisposition: throws when deels_gegrond oordeel has no toelichting.
     *
     * @return void
     */
    public function testSubmitDispositionThrowsForDeelsGegrondenWithoutToelichting(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Toelichting/i');

        $this->service->submitDisposition('complaint-uuid', ['oordeel' => 'deels_gegrond']);
    }//end testSubmitDispositionThrowsForDeelsGegrondenWithoutToelichting()

    /**
     * submitDisposition: succeeds for ongegrond without toelichting.
     *
     * @return void
     */
    public function testSubmitDispositionSucceedsForOngegrondenWithoutToelichting(): void
    {
        $objectServiceMock = $this->createMock(DispositionObjectServiceStub::class);
        $this->settingsService->method('getObjectService')->willReturn($objectServiceMock);
        $this->settingsService
            ->method('getConfigValue')
            ->willReturnMap([
                ['register', '', 'procest'],
                ['complaint_disposition_schema', '', 'complaintDisposition'],
            ]);

        $savedDisposition = ['oordeel' => 'ongegrond', 'complaint' => 'complaint-uuid'];
        $objectServiceMock->method('saveObject')->willReturn($savedDisposition);

        $result = $this->service->submitDisposition('complaint-uuid', ['oordeel' => 'ongegrond']);
        $this->assertSame('ongegrond', $result['oordeel']);
    }//end testSubmitDispositionSucceedsForOngegrondenWithoutToelichting()

    /**
     * submitDisposition: sets goedkeuringStatus when approval is required.
     *
     * @return void
     */
    public function testSubmitDispositionSetsApprovalStatusWhenRequired(): void
    {
        $objectServiceMock = $this->createMock(DispositionObjectServiceStub::class);
        $this->settingsService->method('getObjectService')->willReturn($objectServiceMock);
        $this->settingsService->method('getConfigValue')->willReturn('procest');

        $objectServiceMock
            ->method('saveObject')
            ->willReturnCallback(
                function (array $object, array $extend=[], ?string $register=null, ?string $schema=null, ?string $uuid=null) {
                    $this->assertSame('wacht_op_goedkeuring', $object['goedkeuringStatus']);
                    return $object;
                }
            );

        $result = $this->service->submitDispositionForApproval(
            'complaint-uuid',
            ['oordeel' => 'ongegrond', 'afsluitdatum' => '2026-04-01']
        );

        $this->assertSame('wacht_op_goedkeuring', $result['goedkeuringStatus']);
    }//end testSubmitDispositionSetsApprovalStatusWhenRequired()

    /**
     * approveDisposition: sets goedkeuringStatus to goedgekeurd.
     *
     * @return void
     */
    public function testApproveDispositionSetsStatusToGoedgekeurd(): void
    {
        $objectServiceMock = $this->createMock(DispositionObjectServiceStub::class);
        $this->settingsService->method('getObjectService')->willReturn($objectServiceMock);
        $this->settingsService->method('getConfigValue')->willReturn('procest');

        $objectServiceMock
            ->method('saveObject')
            ->willReturnCallback(
                function (array $object, array $extend=[], ?string $register=null, ?string $schema=null, ?string $uuid=null) {
                    $this->assertSame('goedgekeurd', $object['goedkeuringStatus']);
                    $this->assertSame('coordinator-uid', $object['goedkeurder']);
                    return $object;
                }
            );

        $result = $this->service->approveDisposition('disposition-uuid', 'coordinator-uid');
        $this->assertSame('goedgekeurd', $result['goedkeuringStatus']);
    }//end testApproveDispositionSetsStatusToGoedgekeurd()

    /**
     * getDispositionForComplaint: returns null when OpenRegister unavailable.
     *
     * @return void
     */
    public function testGetDispositionForComplaintReturnsNullWhenUnavailable(): void
    {
        $this->settingsService->method('getObjectService')->willReturn(null);
        $result = $this->service->getDispositionForComplaint('complaint-uuid');
        $this->assertNull($result);
    }//end testGetDispositionForComplaintReturnsNullWhenUnavailable()

    /**
     * generateResponseLetter: returns a queued status result.
     *
     * @return void
     */
    public function testGenerateResponseLetterReturnsQueuedStatus(): void
    {
        $result = $this->service->generateResponseLetter('complaint-uuid', 'disposition-uuid');
        $this->assertSame('queued', $result['status']);
        $this->assertSame('complaint-uuid', $result['complaintId']);
    }//end testGenerateResponseLetterReturnsQueuedStatus()

}//end class
