<?php

/**
 * VergaderingCaseService Unit Tests
 *
 * Tests for the VergaderingCaseService that wraps ORI vergaderingen as
 * Procest cases with lifecycle and deadline tracking.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\VergaderingCaseService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Minimal ObjectService stub matching the named-argument signatures used in
 * VergaderingCaseService so that createMock() honours named args.
 *
 * Using an interface (not stdClass::addMethods) avoids "Unknown named parameter"
 * errors in PHPUnit 10 when named arguments are passed.
 */
interface VergaderingObjectServiceStub {
	/**
	 * Save or update an object in the register.
	 *
	 * @param string $register The register name
	 * @param string $schema The schema slug
	 * @param array $object The object data
	 * @param string $id Optional ID for update (empty for create)
	 *
	 * @return array
	 */
	public function saveObject(string $register, string $schema, array $object, string $id = ''): array;

	/**
	 * Slug-aware search bridge (real ObjectService::searchObjectsBySlug()).
	 *
	 * @param string $registerSlug The register slug
	 * @param string $schemaSlug The schema slug
	 * @param array<string,mixed> $filters Query parameters
	 *
	 * @return array<int,mixed>|int
	 */
	public function searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters = []): array|int;

	/**
	 * Search objects (real ObjectService::searchObjects()).
	 *
	 * @param array<string,mixed> $query Query with @self block and field filters.
	 *
	 * @return array<int,mixed>|int
	 */
	public function searchObjects(array $query = []): array|int;
}//end interface

/**
 * Unit tests for VergaderingCaseService.
 *
 * @covers \OCA\Procest\Service\VergaderingCaseService
 */
class VergaderingCaseServiceTest extends TestCase {

	/**
	 * The mocked settings service.
	 *
	 * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * The mocked logger.
	 *
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The service under test.
	 *
	 * @var VergaderingCaseService
	 */
	private VergaderingCaseService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new VergaderingCaseService(
			settingsService: $this->settingsService,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * Test createForVergadering() returns empty array when ObjectService is unavailable.
	 *
	 * @return void
	 */
	public function testCreateForVergaderingReturnsEmptyWhenNoObjectService(): void {
		$this->settingsService
			->method('getObjectService')
			->willReturn(null);

		$result = $this->service->createForVergadering(
			vergadering: ['name' => 'Test', 'startDate' => '2026-06-15T19:00:00+02:00']
		);

		$this->assertSame([], $result);

	}//end testCreateForVergaderingReturnsEmptyWhenNoObjectService()

	/**
	 * Test createForVergadering() returns empty array when register config is missing.
	 *
	 * @return void
	 */
	public function testCreateForVergaderingReturnsEmptyWhenConfigMissing(): void {
		$objectService = $this->createMock(VergaderingObjectServiceStub::class);
		$objectService->expects($this->never())->method('saveObject');

		$this->settingsService->method('getObjectService')->willReturn($objectService);
		$this->settingsService->method('getConfigValue')->willReturn('');

		$result = $this->service->createForVergadering(
			vergadering: ['name' => 'Test', 'startDate' => '2026-06-15T19:00:00+02:00']
		);

		$this->assertSame([], $result);

	}//end testCreateForVergaderingReturnsEmptyWhenConfigMissing()

	/**
	 * Test createForVergadering() sets deadline to startDatum − 7 days.
	 *
	 * @return void
	 */
	public function testCreateForVergaderingCalculatesDeadlineMinusSevenDays(): void {
		$capturedObject = null;

		$objectService = $this->createMock(VergaderingObjectServiceStub::class);
		$objectService
			->method('saveObject')
			->willReturnCallback(
				static function (string $register, string $schema, array $object) use (&$capturedObject): array {
					$capturedObject = $object;
					return ['id' => 'case-123'];
				}
			);

		$this->settingsService->method('getObjectService')->willReturn($objectService);
		$this->settingsService->method('getConfigValue')
			->willReturnMap([
				['register', '', 'procest-register'],
				['case_schema', '', 'case-schema-id'],
			]);

		$vergadering = [
			'@self' => ['slug' => 'raadsvergadering-2026-06-15'],
			'name' => 'Raadsvergadering 15 juni 2026',
			'startDate' => '2026-06-15T19:00:00+02:00',
			'type' => 'raadsvergadering',
			'organisation' => 'Gemeente Voorbeeldstad',
		];

		$result = $this->service->createForVergadering(vergadering: $vergadering);

		$this->assertSame(['id' => 'case-123'], $result);
		$this->assertSame('planned', $capturedObject['status']);
		$this->assertSame('2026-06-08', $capturedObject['deadline']);

	}//end testCreateForVergaderingCalculatesDeadlineMinusSevenDays()

	/**
	 * Test advanceStatus() throws on invalid status.
	 *
	 * @return void
	 */
	public function testAdvanceStatusThrowsOnInvalidStatus(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/Invalid vergadering case status/');

		$this->service->advanceStatus(caseId: 'case-123', newStatus: 'invalid');

	}//end testAdvanceStatusThrowsOnInvalidStatus()

	/**
	 * Test advanceStatus() throws when ObjectService is unavailable.
	 *
	 * @return void
	 */
	public function testAdvanceStatusThrowsWhenNoObjectService(): void {
		$this->settingsService
			->method('getObjectService')
			->willReturn(null);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/ObjectService is not available/');

		$this->service->advanceStatus(caseId: 'case-123', newStatus: 'lopend');

	}//end testAdvanceStatusThrowsWhenNoObjectService()

	/**
	 * Test advanceStatus() saves the new status via ObjectService.
	 *
	 * @return void
	 */
	public function testAdvanceStatusSavesNewStatus(): void {
		$objectService = $this->createMock(VergaderingObjectServiceStub::class);
		$objectService
			->expects($this->once())
			->method('saveObject')
			->willReturn(['id' => 'case-123', 'status' => 'lopend']);

		$this->settingsService->method('getObjectService')->willReturn($objectService);
		$this->settingsService->method('getConfigValue')
			->willReturnMap([
				['register', '', 'procest-register'],
				['case_schema', '', 'case-schema-id'],
			]);

		$result = $this->service->advanceStatus(caseId: 'case-123', newStatus: 'lopend');

		$this->assertSame('lopend', $result['status']);

	}//end testAdvanceStatusSavesNewStatus()

	/**
	 * Test checkDeadlines() returns 0 when ObjectService is unavailable.
	 *
	 * @return void
	 */
	public function testCheckDeadlinesReturnsZeroWhenNoObjectService(): void {
		$this->settingsService->method('getObjectService')->willReturn(null);

		$count = $this->service->checkDeadlines();

		$this->assertSame(0, $count);

	}//end testCheckDeadlinesReturnsZeroWhenNoObjectService()

	/**
	 * Test checkDeadlines() advances cases that have reached their deadline.
	 *
	 * @return void
	 */
	public function testCheckDeadlinesAdvancesCasesOnDeadlineDate(): void {
		$objectService = $this->createMock(VergaderingObjectServiceStub::class);
		$today = (new \DateTimeImmutable('today'))->format('Y-m-d');

		$objectService
			->method('searchObjectsBySlug')
			->willReturn([
				['id' => 'case-1', 'status' => 'planned', 'deadline' => $today],
				['id' => 'case-2', 'status' => 'planned', 'deadline' => $today],
			]);

		$objectService
			->expects($this->exactly(2))
			->method('saveObject')
			->willReturn(['id' => 'case-x', 'status' => 'lopend']);

		$this->settingsService->method('getObjectService')->willReturn($objectService);
		$this->settingsService->method('getConfigValue')
			->willReturnMap([
				['register', '', 'procest-register'],
				['case_schema', '', 'case-schema-id'],
			]);

		$count = $this->service->checkDeadlines();

		$this->assertSame(2, $count);

	}//end testCheckDeadlinesAdvancesCasesOnDeadlineDate()

}//end class
