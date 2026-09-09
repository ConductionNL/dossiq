<?php

/**
 * ComplaintService Unit Tests
 *
 * Tests for complaint lifecycle, Awb deadline calculation, working-day math,
 * verdaging logic, and status transitions.
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
 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-02
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\ComplaintService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\WorkingDayCalculator;
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
interface ComplaintObjectServiceStub {
	/**
	 * Find a single object by ID (real OpenRegister ObjectService::find()).
	 *
	 * @param int|string $id Object UUID
	 * @param mixed ...$args Remaining find() args (extend/files/register/schema).
	 *
	 * @return mixed
	 */
	public function find(int|string $id, ...$args): mixed;

	/**
	 * Search objects (real ObjectService::searchObjects()).
	 *
	 * @param array<string,mixed> $query Query with @self block and field filters.
	 *
	 * @return array<int,mixed>|int
	 */
	public function searchObjects(array $query = []): array|int;

	/**
	 * Slug-aware search bridge (real ObjectService::searchObjectsBySlug()).
	 *
	 * @param string $registerSlug Register slug.
	 * @param string $schemaSlug Schema slug.
	 * @param array<string,mixed> $filters Field filters and pagination keys.
	 *
	 * @return array<int,mixed>|int
	 */
	public function searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters = []): array|int;

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
 * Unit tests for ComplaintService.
 *
 * @covers \OCA\Dossiq\Service\ComplaintService
 * @uses \OCA\Dossiq\Service\WorkingDayCalculator
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class ComplaintServiceTest extends TestCase {

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
	protected function setUp(): void {
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new ComplaintService(
			settingsService: $this->settingsService,
			logger: $this->logger,
			workingDays: new WorkingDayCalculator(),
		);
	}//end setUp()

	/**
	 * Awb: 5 working days from 2026-03-01 (Monday) = 2026-03-08 (Monday after weekend skip).
	 *
	 * @return void
	 */
	public function testAddWorkingDaysSkipsWeekend(): void {
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
	public function testAddWorkingDaysSkipsBothWeekendDays(): void {
		// 2026-04-30 (Thursday) + 2 WD = skips weekend => 2026-05-04 (Monday).
		$result = $this->service->addWorkingDays('2026-04-30', 2);
		$this->assertSame('2026-05-04', $result);
	}//end testAddWorkingDaysSkipsBothWeekendDays()

	/**
	 * Awb: working-day calculator skips Nieuwjaarsdag (01-01).
	 *
	 * @return void
	 */
	public function testAddWorkingDaysSkipsNieuwjaarsdag(): void {
		// 2026-12-31 (Thursday) + 1 WD: Jan 1 is holiday, Jan 2 Saturday, Jan 3 Sunday → result = 2027-01-04.
		$result = $this->service->addWorkingDays('2026-12-31', 1);
		$this->assertSame('2027-01-04', $result);
	}//end testAddWorkingDaysSkipsNieuwjaarsdag()

	/**
	 * Awb: isWorkingDay returns false for Saturday.
	 *
	 * @return void
	 */
	public function testIsWorkingDayReturnsFalseForSaturday(): void {
		$saturday = new \DateTimeImmutable('2026-03-07'); // Saturday
		$this->assertFalse($this->service->isWorkingDay($saturday));
	}//end testIsWorkingDayReturnsFalseForSaturday()

	/**
	 * Awb: isWorkingDay returns false for Sunday.
	 *
	 * @return void
	 */
	public function testIsWorkingDayReturnsFalseForSunday(): void {
		$sunday = new \DateTimeImmutable('2026-03-08'); // Sunday
		$this->assertFalse($this->service->isWorkingDay($sunday));
	}//end testIsWorkingDayReturnsFalseForSunday()

	/**
	 * Awb: isWorkingDay returns true for a regular Monday.
	 *
	 * @return void
	 */
	public function testIsWorkingDayReturnsTrueForMonday(): void {
		$monday = new \DateTimeImmutable('2026-03-09'); // Monday
		$this->assertTrue($this->service->isWorkingDay($monday));
	}//end testIsWorkingDayReturnsTrueForMonday()

	/**
	 * Awb: isWorkingDay returns false for Koningsdag (04-27).
	 *
	 * @return void
	 */
	public function testIsWorkingDayReturnsFalseForKoningsdag(): void {
		$koningsdag = new \DateTimeImmutable('2026-04-27'); // Koningsdag (Monday)
		$this->assertFalse($this->service->isWorkingDay($koningsdag));
	}//end testIsWorkingDayReturnsFalseForKoningsdag()

	/**
	 * Awb: isWorkingDay returns false for Eerste Kerstdag (12-25).
	 *
	 * @return void
	 */
	public function testIsWorkingDayReturnsFalseForKerstdag(): void {
		$kerstdag = new \DateTimeImmutable('2026-12-25');
		$this->assertFalse($this->service->isWorkingDay($kerstdag));
	}//end testIsWorkingDayReturnsFalseForKerstdag()

	/**
	 * Awb: 6-week calendar deadline from 2026-03-01 = 2026-04-12.
	 *
	 * @return void
	 */
	public function testAddCalendarWeeksProducesCorrectDeadline(): void {
		$result = $this->service->addCalendarWeeks('2026-03-01', 6);
		$this->assertSame('2026-04-12', $result);
	}//end testAddCalendarWeeksProducesCorrectDeadline()

	/**
	 * Verdaging: adds 4 weeks to the existing afhandelDeadline and sets verdagingMogelijk=false.
	 *
	 * @return void
	 */
	public function testRequestVerdagingUpdatesDeadlineAndSetsFlag(): void {
		$complaint = [
			'postponementPossible' => true,
			'afhandelDeadline' => '2026-04-12',
		];

		$objectServiceMock = $this->createMock(ComplaintObjectServiceStub::class);

		$this->settingsService
			->method('getObjectService')
			->willReturn($objectServiceMock);

		$this->settingsService
			->method('getConfigValue')
			->willReturnMap([
				['register', '', 'dossiq'],
				['complaint_schema', '', 'complaint'],
			]);

		// The service calls find() to retrieve the complaint.
		$objectServiceMock
			->method('find')
			->willReturn($complaint);

		// saveObject receives the updated data.
		$objectServiceMock
			->method('saveObject')
			->willReturnCallback(
				function (array $data, string $reg, string $sch, ?string $id = null) {
					$this->assertFalse($data['postponementPossible']);
					$this->assertSame('2026-05-10', $data['afhandelDeadline']);
					return $data;
				}
			);

		$result = $this->service->requestVerdaging('uuid-123', 'Complexe zaak vereist extra onderzoek');
		$this->assertFalse($result['postponementPossible']);
	}//end testRequestVerdagingUpdatesDeadlineAndSetsFlag()

	/**
	 * Verdaging: throws when verdagingMogelijk is already false.
	 *
	 * @return void
	 */
	public function testRequestVerdagingThrowsWhenExtensionNotAvailable(): void {
		$complaint = ['postponementPossible' => false, 'afhandelDeadline' => '2026-04-12'];

		$objectServiceMock = $this->createMock(ComplaintObjectServiceStub::class);
		$this->settingsService->method('getObjectService')->willReturn($objectServiceMock);
		$this->settingsService->method('getConfigValue')->willReturn('dossiq');
		$objectServiceMock->method('find')->willReturn($complaint);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/not available/i');

		$this->service->requestVerdaging('uuid-123', 'justificatie');
	}//end testRequestVerdagingThrowsWhenExtensionNotAvailable()

	/**
	 * Verdaging: throws when justificatie is empty.
	 *
	 * @return void
	 */
	public function testRequestVerdagingThrowsWhenJustificatieEmpty(): void {
		$complaint = ['postponementPossible' => true, 'afhandelDeadline' => '2026-04-12'];

		$objectServiceMock = $this->createMock(ComplaintObjectServiceStub::class);
		$this->settingsService->method('getObjectService')->willReturn($objectServiceMock);
		$this->settingsService->method('getConfigValue')->willReturn('dossiq');
		$objectServiceMock->method('find')->willReturn($complaint);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/Justificatie/i');

		$this->service->requestVerdaging('uuid-123', '');
	}//end testRequestVerdagingThrowsWhenJustificatieEmpty()

	/**
	 * Status transition: valid transition from ontvangen to ontvangst_bevestigd succeeds.
	 *
	 * @return void
	 */
	public function testTransitionStatusSucceedsForValidTransition(): void {
		$complaint = ['status' => 'received'];

		$objectServiceMock = $this->createMock(ComplaintObjectServiceStub::class);
		$this->settingsService->method('getObjectService')->willReturn($objectServiceMock);
		$this->settingsService->method('getConfigValue')->willReturn('dossiq');
		$objectServiceMock->method('find')->willReturn($complaint);
		$objectServiceMock->method('saveObject')->willReturn(['status' => 'receipt_confirmed']);

		$result = $this->service->transitionStatus('uuid-123', 'receipt_confirmed');
		$this->assertSame('receipt_confirmed', $result['status']);
	}//end testTransitionStatusSucceedsForValidTransition()

	/**
	 * Status transition: invalid transition throws RuntimeException.
	 *
	 * @return void
	 */
	public function testTransitionStatusThrowsForInvalidTransition(): void {
		$complaint = ['status' => 'received'];

		$objectServiceMock = $this->createMock(ComplaintObjectServiceStub::class);
		$this->settingsService->method('getObjectService')->willReturn($objectServiceMock);
		$this->settingsService->method('getConfigValue')->willReturn('dossiq');
		$objectServiceMock->method('find')->willReturn($complaint);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/not allowed/i');

		$this->service->transitionStatus('uuid-123', 'handled');
	}//end testTransitionStatusThrowsForInvalidTransition()

	/**
	 * createComplaint: throws when required fields are missing.
	 *
	 * @return void
	 */
	public function testCreateComplaintThrowsWhenRequiredFieldsMissing(): void {
		$this->settingsService->method('getObjectService')->willReturn(null);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/Required fields/i');

		$this->service->createComplaint(['handler' => 'user1']);
	}//end testCreateComplaintThrowsWhenRequiredFieldsMissing()

	/**
	 * createComplaint: throws when OpenRegister is not available.
	 *
	 * @return void
	 */
	public function testCreateComplaintThrowsWhenOpenRegisterUnavailable(): void {
		$this->settingsService->method('getObjectService')->willReturn(null);

		$this->expectException(\RuntimeException::class);

		// This will throw "Required fields missing" before OpenRegister check
		// for onderwerp/omschrijving/ontvangstdatum; pass them all.
		$this->service->createComplaint([
			'subject' => 'Test',
			'omschrijving' => 'Description',
			'receiptDate' => '2026-03-01',
		]);
	}//end testCreateComplaintThrowsWhenOpenRegisterUnavailable()

	/**
	 * Build a fake object service that answers the slug search path with a
	 * fixed list of complaint rows.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows to return.
	 *
	 * @return object The fake object service.
	 */
	private function fakeObjectService(array $rows): object {
		return new class($rows) {
			/**
			 * @param array<int, array<string, mixed>> $rows The rows to return.
			 */
			public function __construct(private readonly array $rows) {
			}//end __construct()

			/**
			 * @param string $register The register slug.
			 * @param string $schema The schema slug.
			 * @param array<string, mixed> $filters The query filters.
			 *
			 * @return array<int, array<string, mixed>> The scripted rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				return $this->rows;
			}//end searchObjectsBySlug()
		};
	}//end fakeObjectService()

	/**
	 * Point the settings mock at a configured register and complaint schema.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows the store returns.
	 *
	 * @return void
	 */
	private function withComplaints(array $rows): void {
		$this->settingsService->method('getObjectService')
			->willReturn($this->fakeObjectService(rows: $rows));
		$this->settingsService->method('getConfigValue')
			->willReturnCallback(
				static function (string $key): string {
					return ($key === 'register' ? 'dossiq-register' : 'complaint');
				}
			);
	}//end withComplaints()

	/**
	 * listComplaints returns nothing when OpenRegister is unavailable.
	 *
	 * @return void
	 */
	public function testListComplaintsReturnsEmptyWithoutObjectService(): void {
		$this->settingsService->method('getObjectService')->willReturn(null);

		$this->assertSame([], $this->service->listComplaints());
	}//end testListComplaintsReturnsEmptyWithoutObjectService()

	/**
	 * listComplaints returns nothing when the register or schema is unset.
	 *
	 * @return void
	 */
	public function testListComplaintsReturnsEmptyWhenNotConfigured(): void {
		$this->settingsService->method('getObjectService')
			->willReturn($this->fakeObjectService(rows: [['id' => 'a']]));
		$this->settingsService->method('getConfigValue')->willReturn('');

		$this->assertSame([], $this->service->listComplaints());
	}//end testListComplaintsReturnsEmptyWhenNotConfigured()

	/**
	 * listComplaints hands the store rows back as arrays.
	 *
	 * @return void
	 */
	public function testListComplaintsReturnsTheStoreRows(): void {
		$this->withComplaints(rows: [['id' => 'a'], ['id' => 'b']]);

		$this->assertSame([['id' => 'a'], ['id' => 'b']], $this->service->listComplaints());
	}//end testListComplaintsReturnsTheStoreRows()

	/**
	 * getDeadlineAlerts splits complaints into overdue and warning.
	 *
	 * Dates are built relative to today so the test does not rot. A deadline in
	 * the past is overdue; one inside the warning window is a warning; one well
	 * beyond it is neither; one with no deadline at all is skipped rather than
	 * reported.
	 *
	 * @return void
	 */
	public function testGetDeadlineAlertsGroupsOverdueAndWarning(): void {
		$today = new \DateTimeImmutable('today');
		$this->withComplaints(
			rows: [
				['id' => 'overdue', 'afhandelDeadline' => $today->modify('-1 day')->format('Y-m-d')],
				['id' => 'due-today', 'afhandelDeadline' => $today->format('Y-m-d')],
				['id' => 'warning', 'afhandelDeadline' => $today->modify('+2 days')->format('Y-m-d')],
				// Exactly on the default 3-day window: included, so a <= that
				// slips to < is caught here.
				['id' => 'on-the-boundary', 'afhandelDeadline' => $today->modify('+3 days')->format('Y-m-d')],
				// One day past it: excluded, so a <= that slips to <= +1 is
				// caught too.
				['id' => 'just-outside', 'afhandelDeadline' => $today->modify('+4 days')->format('Y-m-d')],
				['id' => 'comfortable', 'afhandelDeadline' => $today->modify('+30 days')->format('Y-m-d')],
				['id' => 'no-deadline'],
			]
		);

		$alerts = $this->service->getDeadlineAlerts();

		$this->assertSame(['overdue'], array_column($alerts['overdue'], 'id'));
		$this->assertSame(
			['due-today', 'warning', 'on-the-boundary'],
			array_column($alerts['warning'], 'id')
		);
	}//end testGetDeadlineAlertsGroupsOverdueAndWarning()

	/**
	 * getDeadlineAlerts honours a widened warning window.
	 *
	 * @return void
	 */
	public function testGetDeadlineAlertsHonoursTheWarningWindow(): void {
		$today = new \DateTimeImmutable('today');
		$this->withComplaints(
			rows: [
				['id' => 'in-ten-days', 'afhandelDeadline' => $today->modify('+10 days')->format('Y-m-d')],
			]
		);

		$this->assertSame([], $this->service->getDeadlineAlerts()['warning']);
		$this->assertSame(
			['in-ten-days'],
			array_column($this->service->getDeadlineAlerts(warningDays: 14)['warning'], 'id')
		);
	}//end testGetDeadlineAlertsHonoursTheWarningWindow()

}//end class
