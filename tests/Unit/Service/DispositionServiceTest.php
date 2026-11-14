<?php

/**
 * DispositionService Unit Tests
 *
 * Tests for complaint disposition submission, approval, rejection, and
 * validation of oordeel values.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\DispositionService;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Object service stub for disposition tests (OpenRegister object-first signature).
 */
interface DispositionObjectServiceStub {
	/**
	 * Save an object (OpenRegister object-first signature).
	 *
	 * @param array $object Object data.
	 * @param array $extend Extend parameters.
	 * @param string|null $register Register id.
	 * @param string|null $schema Schema id.
	 * @param string|null $uuid Optional object uuid.
	 *
	 * @return array
	 */
	public function saveObject(array $object, array $extend = [], ?string $register = null, ?string $schema = null, ?string $uuid = null);

	/**
	 * Find objects matching a filter.
	 *
	 * @param string $register Register id.
	 * @param string $schema Schema id.
	 * @param array $filters Query filters.
	 *
	 * @return array
	 */
	public function findObjects(string $register, string $schema, array $filters = []);

	/**
	 * Merge fields onto a stored object (OpenRegister's PATCH seam).
	 *
	 * @param string $objectId The object uuid.
	 * @param array $data The fields to change.
	 * @param string|null $register Register id.
	 * @param string|null $schema Schema id.
	 *
	 * @return array
	 */
	public function patchObject(string $objectId, array $data, ?string $register = null, ?string $schema = null);
}//end interface

/**
 * Unit tests for DispositionService.
 *
 * @covers \OCA\Dossiq\Service\DispositionService
 */
class DispositionServiceTest extends TestCase {

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
	protected function setUp(): void {
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

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
	public function testSubmitDispositionThrowsForInvalidOordeel(): void {
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/Invalid oordeel/i');

		$this->service->submitDisposition('complaint-uuid', ['opinion' => 'unknown']);
	}//end testSubmitDispositionThrowsForInvalidOordeel()

	/**
	 * submitDisposition: throws when gegrond oordeel has no toelichting.
	 *
	 * @return void
	 */
	public function testSubmitDispositionThrowsWhenGegrondenMissingToelichting(): void {
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/Toelichting/i');

		$this->service->submitDisposition('complaint-uuid', ['opinion' => 'upheld']);
	}//end testSubmitDispositionThrowsWhenGegrondenMissingToelichting()

	/**
	 * submitDisposition: throws when deels_gegrond oordeel has no toelichting.
	 *
	 * @return void
	 */
	public function testSubmitDispositionThrowsForDeelsGegrondenWithoutToelichting(): void {
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/Toelichting/i');

		$this->service->submitDisposition('complaint-uuid', ['opinion' => 'partly_upheld']);
	}//end testSubmitDispositionThrowsForDeelsGegrondenWithoutToelichting()

	/**
	 * submitDisposition: succeeds for ongegrond without toelichting.
	 *
	 * @return void
	 */
	public function testSubmitDispositionSucceedsForOngegrondenWithoutToelichting(): void {
		$objectServiceMock = $this->createMock(DispositionObjectServiceStub::class);
		$this->settingsService->method('getObjectService')->willReturn($objectServiceMock);
		$this->settingsService
			->method('getConfigValue')
			->willReturnMap([
				['register', '', 'dossiq'],
				['complaint_disposition_schema', '', 'complaintDisposition'],
			]);

		$savedDisposition = ['opinion' => 'dismissed', 'complaint' => 'complaint-uuid'];
		$objectServiceMock->method('saveObject')->willReturn($savedDisposition);

		$result = $this->service->submitDisposition('complaint-uuid', ['opinion' => 'dismissed']);
		$this->assertSame('dismissed', $result['opinion']);
	}//end testSubmitDispositionSucceedsForOngegrondenWithoutToelichting()

	/**
	 * submitDisposition: sets goedkeuringStatus when approval is required.
	 *
	 * @return void
	 */
	public function testSubmitDispositionSetsApprovalStatusWhenRequired(): void {
		$objectServiceMock = $this->createMock(DispositionObjectServiceStub::class);
		$this->settingsService->method('getObjectService')->willReturn($objectServiceMock);
		$this->settingsService->method('getConfigValue')->willReturn('dossiq');

		$objectServiceMock
			->method('saveObject')
			->willReturnCallback(
				function (array $object, array $extend = [], ?string $register = null, ?string $schema = null, ?string $uuid = null) {
					$this->assertSame('awaiting_approval', $object['approvalStatus']);
					return $object;
				}
			);

		$result = $this->service->submitDispositionForApproval(
			'complaint-uuid',
			['opinion' => 'dismissed', 'closureDate' => '2026-04-01']
		);

		$this->assertSame('awaiting_approval', $result['approvalStatus']);
	}//end testSubmitDispositionSetsApprovalStatusWhenRequired()

	/**
	 * approveDisposition: sets goedkeuringStatus to goedgekeurd.
	 *
	 * @return void
	 */
	public function testApproveDispositionSetsStatusToGoedgekeurd(): void {
		$objectServiceMock = $this->createMock(DispositionObjectServiceStub::class);
		$this->settingsService->method('getObjectService')->willReturn($objectServiceMock);
		$this->settingsService->method('getConfigValue')->willReturn('dossiq');

		// The approval owns two fields, so it PATCHES them onto the stored
		// disposition. A bare saveObject() with the uuid replaced the whole
		// disposition with these two fields, which the schema refuses for the
		// complaint, opinion and closure date it requires.
		$objectServiceMock->expects($this->never())->method('saveObject');
		$objectServiceMock
			->expects($this->once())
			->method('patchObject')
			->willReturnCallback(
				function (string $objectId, array $data) {
					$this->assertSame('disposition-uuid', $objectId);
					$this->assertSame(['approvalStatus' => 'approved', 'goedkeurder' => 'coordinator-uid'], $data);
					return array_merge(['complaint' => 'complaint-uuid', 'opinion' => 'dismissed'], $data);
				}
			);

		$result = $this->service->approveDisposition('disposition-uuid', 'coordinator-uid');
		$this->assertSame('complaint-uuid', $result['complaint'], 'the stored disposition comes back whole');
		$this->assertSame('approved', $result['approvalStatus']);
	}//end testApproveDispositionSetsStatusToGoedgekeurd()

	/**
	 * getDispositionForComplaint: returns null when OpenRegister unavailable.
	 *
	 * @return void
	 */
	public function testGetDispositionForComplaintReturnsNullWhenUnavailable(): void {
		$this->settingsService->method('getObjectService')->willReturn(null);
		$result = $this->service->getDispositionForComplaint('complaint-uuid');
		$this->assertNull($result);
	}//end testGetDispositionForComplaintReturnsNullWhenUnavailable()

	/**
	 * generateResponseLetter: returns a queued status result.
	 *
	 * @return void
	 */
	public function testGenerateResponseLetterReturnsQueuedStatus(): void {
		$result = $this->service->generateResponseLetter('complaint-uuid', 'disposition-uuid');
		$this->assertSame('queued', $result['status']);
		$this->assertSame('complaint-uuid', $result['complaintId']);
	}//end testGenerateResponseLetterReturnsQueuedStatus()

}//end class
