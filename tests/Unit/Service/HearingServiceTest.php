<?php

/**
 * HearingService Unit Tests
 *
 * Tests for hearing scheduling, outcome recording, and Talk room creation.
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
 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-03
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\HearingService;
use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Typed stub for the OpenRegister ObjectService.
 *
 * HearingService calls ObjectService::saveObject() with named arguments
 * (object:/register:/schema:/uuid:). A bare addMethods() magic mock rejects
 * named arguments with "Unknown named parameter"; this typed interface lets
 * PHPUnit generate a mock whose signature accepts them.
 */
interface HearingObjectServiceStub {
	/**
	 * Find a single object by ID.
	 *
	 * @param string $register Register slug
	 * @param string $schema Schema slug
	 * @param string $id Object UUID
	 *
	 * @return array<string,mixed>|null
	 */
	public function findObject(string $register, string $schema, string $id): ?array;

	/**
	 * Find objects matching a filter.
	 *
	 * @param string $register Register slug
	 * @param string $schema Schema slug
	 * @param array<string,mixed> $filters Filter criteria
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function findObjects(string $register, string $schema, array $filters): array;

	/**
	 * Save or update an object.
	 *
	 * @param array<string,mixed> $object Object data
	 * @param string $register Register slug
	 * @param string $schema Schema slug
	 * @param string|null $uuid Optional object UUID for updates
	 *
	 * @return array<string,mixed>
	 */
	public function saveObject(array $object, string $register, string $schema, ?string $uuid = null): array;
}//end interface

/**
 * Unit tests for HearingService.
 *
 * @covers \OCA\Procest\Service\HearingService
 */
class HearingServiceTest extends TestCase {

	/**
	 * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * @var HearingService
	 */
	private HearingService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new HearingService(
			settingsService: $this->settingsService,
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * scheduleHearing: throws when datum is missing.
	 *
	 * @return void
	 */
	public function testScheduleHearingThrowsWhenDatumMissing(): void {
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/datum/i');

		$this->service->scheduleHearing('complaint-uuid', ['type' => 'fysiek']);
	}//end testScheduleHearingThrowsWhenDatumMissing()

	/**
	 * scheduleHearing: throws when type is missing.
	 *
	 * @return void
	 */
	public function testScheduleHearingThrowsWhenTypeMissing(): void {
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/type/i');

		$this->service->scheduleHearing('complaint-uuid', ['date' => '2026-04-01T10:00:00']);
	}//end testScheduleHearingThrowsWhenTypeMissing()

	/**
	 * scheduleHearing: throws when OpenRegister is not available.
	 *
	 * @return void
	 */
	public function testScheduleHearingThrowsWhenOpenRegisterUnavailable(): void {
		$this->settingsService->method('getObjectService')->willReturn(null);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/OpenRegister/i');

		$this->service->scheduleHearing('complaint-uuid', [
			'date' => '2026-04-01T10:00:00',
			'type' => 'fysiek',
		]);
	}//end testScheduleHearingThrowsWhenOpenRegisterUnavailable()

	/**
	 * scheduleHearing: succeeds for a fysiek hearing.
	 *
	 * @return void
	 */
	public function testScheduleHearingSucceedsForFysiekHearing(): void {
		$objectServiceMock = $this->createMock(HearingObjectServiceStub::class);
		$this->settingsService->method('getObjectService')->willReturn($objectServiceMock);
		$this->settingsService
			->method('getConfigValue')
			->willReturnMap([
				['register', '', 'procest'],
				['hearing_schema', '', 'hearing'],
			]);

		$savedHearing = [
			'complaint' => 'complaint-uuid',
			'date' => '2026-04-01T10:00:00',
			'type' => 'fysiek',
			'location' => 'Stadhuis kamer 12',
		];

		$objectServiceMock->method('saveObject')->willReturn($savedHearing);

		$result = $this->service->scheduleHearing('complaint-uuid', [
			'date' => '2026-04-01T10:00:00',
			'type' => 'fysiek',
			'location' => 'Stadhuis kamer 12',
		]);

		$this->assertSame('complaint-uuid', $result['complaint']);
		$this->assertSame('fysiek', $result['type']);
	}//end testScheduleHearingSucceedsForFysiekHearing()

	/**
	 * recordOutcome: throws when verslag is empty.
	 *
	 * @return void
	 */
	public function testRecordOutcomeThrowsWhenVerslagMissing(): void {
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/Verslag/i');

		$this->service->recordOutcome('hearing-uuid', ['conclusion' => 'geen bezwaar']);
	}//end testRecordOutcomeThrowsWhenVerslagMissing()

	/**
	 * recordOutcome: succeeds with verslag present.
	 *
	 * @return void
	 */
	public function testRecordOutcomeSucceedsWithVerslag(): void {
		$objectServiceMock = $this->createMock(HearingObjectServiceStub::class);
		$this->settingsService->method('getObjectService')->willReturn($objectServiceMock);
		$this->settingsService->method('getConfigValue')->willReturn('procest');

		$outcome = [
			'minutes' => 'Klager heeft zijn standpunt toegelicht.',
			'conclusion' => 'Klacht gegrond',
			'dateCompleted' => '2026-04-01',
		];

		$objectServiceMock->method('saveObject')->willReturn($outcome);

		$result = $this->service->recordOutcome('hearing-uuid', $outcome);
		$this->assertSame('Klager heeft zijn standpunt toegelicht.', $result['minutes']);
	}//end testRecordOutcomeSucceedsWithVerslag()

	/**
	 * getHearingsForComplaint: returns empty array when OpenRegister unavailable.
	 *
	 * @return void
	 */
	public function testGetHearingsForComplaintReturnsEmptyWhenUnavailable(): void {
		$this->settingsService->method('getObjectService')->willReturn(null);
		$result = $this->service->getHearingsForComplaint('complaint-uuid');
		$this->assertSame([], $result);
	}//end testGetHearingsForComplaintReturnsEmptyWhenUnavailable()

}//end class
