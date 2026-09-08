<?php

/**
 * CaseNumberService unit tests.
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
 * @spec openspec/specs/case-management/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CaseNumberService;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * ObjectService stub for case-number tests.
 *
 * The signatures mirror OpenRegister's own, NOT the caller's convenience: a
 * fake that agrees with the caller instead of the callee cannot fail, which is
 * how a transposed argument list once survived a full green suite here.
 */
interface CaseNumberObjectServiceStub {

	/**
	 * Search objects by register/schema slug.
	 *
	 * @param string $register The register slug.
	 * @param string $schema   The schema slug.
	 * @param array  $filters  The query filters.
	 *
	 * @return array
	 */
	public function searchObjectsBySlug(string $register, string $schema, array $filters): array;

	/**
	 * Search objects by numeric `@self` query.
	 *
	 * @param array $query The query payload.
	 *
	 * @return array
	 */
	public function searchObjects(array $query): array;

	/**
	 * Save or update an object.
	 *
	 * @param array           $object   The object payload.
	 * @param int|string      $register The register.
	 * @param int|string      $schema   The schema.
	 * @param string|null     $uuid     The uuid to update, or null to create.
	 *
	 * @return array
	 */
	public function saveObject(array $object, int|string $register, int|string $schema, ?string $uuid = null): array;
}//end interface

/**
 * Unit tests for CaseNumberService.
 *
 * @covers \OCA\Dossiq\Service\CaseNumberService
 */
class CaseNumberServiceTest extends TestCase {

	/**
	 * Mocked SettingsService.
	 *
	 * @var SettingsService|MockObject
	 */
	private SettingsService $settings;

	/**
	 * The service under test.
	 *
	 * @var CaseNumberService
	 */
	private CaseNumberService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settings = $this->createMock(SettingsService::class);
		$this->service = new CaseNumberService(
			settingsService: $this->settings,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * The first case of a year is numbered one.
	 *
	 * @return void
	 */
	public function testTheFirstCaseOfAYearIsNumberedOne(): void {
		$this->assertSame('2026-0001', $this->service->nextNumber(year: 2026, identifiers: []));
	}//end testTheFirstCaseOfAYearIsNumberedOne()

	/**
	 * The number follows the highest in use, not the count.
	 *
	 * The distinction is the whole point: after a deletion a count-based number
	 * repeats one that is still on a live case.
	 *
	 * @return void
	 */
	public function testTheNumberFollowsTheHighestNotTheCount(): void {
		$this->assertSame(
			'2026-0042',
			$this->service->nextNumber(
				year: 2026,
				identifiers: ['2026-0041', '2026-0003', '2026-0017']
			)
		);
	}//end testTheNumberFollowsTheHighestNotTheCount()

	/**
	 * The sequence restarts every year.
	 *
	 * @return void
	 */
	public function testTheSequenceRestartsEveryYear(): void {
		$this->assertSame(
			'2027-0001',
			$this->service->nextNumber(year: 2027, identifiers: ['2026-0041', '2026-0042'])
		);
	}//end testTheSequenceRestartsEveryYear()

	/**
	 * A legacy identifier neither raises the sequence nor is disturbed by it.
	 *
	 * @return void
	 */
	public function testALegacyIdentifierIsIgnoredBySequencing(): void {
		$this->assertSame(
			'2026-0002',
			$this->service->nextNumber(
				year: 2026,
				identifiers: ['BZW-2025-17', 'KL-2026-0099', '2026-0001', '2026-']
			)
		);
	}//end testALegacyIdentifierIsIgnoredBySequencing()

	/**
	 * A year past four digits of cases keeps counting rather than wrapping.
	 *
	 * @return void
	 */
	public function testTheSequenceGrowsPastItsPadding(): void {
		$this->assertSame(
			'2026-10000',
			$this->service->nextNumber(year: 2026, identifiers: ['2026-9999'])
		);
	}//end testTheSequenceGrowsPastItsPadding()

	/**
	 * The year comes from the start date, as the calculation's does.
	 *
	 * @return void
	 */
	public function testTheYearComesFromTheStartDate(): void {
		$this->assertSame(2025, $this->service->yearOf(case: ['startDate' => '2025-11-30']));
	}//end testTheYearComesFromTheStartDate()

	/**
	 * A case with no usable start date is numbered in the current year.
	 *
	 * @return void
	 */
	public function testAMissingStartDateFallsBackToThisYear(): void {
		$thisYear = (int)date('Y');
		$this->assertSame($thisYear, $this->service->yearOf(case: []));
		$this->assertSame($thisYear, $this->service->yearOf(case: ['startDate' => '']));
		$this->assertSame($thisYear, $this->service->yearOf(case: ['startDate' => 'not a date']));
	}//end testAMissingStartDateFallsBackToThisYear()

	/**
	 * A case that already holds a number is left alone.
	 *
	 * This is what makes the backfill safe beside OpenRegister's own `sequence`
	 * calculation: when the register filled the field, nothing here writes.
	 *
	 * @return void
	 */
	public function testACaseThatAlreadyHasANumberIsNotTouched(): void {
		$objectService = $this->createMock(CaseNumberObjectServiceStub::class);
		$objectService->expects($this->never())->method('saveObject');
		$this->settings->method('getObjectService')->willReturn($objectService);
		$this->settings->method('getConfigValue')->willReturn('dossiq');

		$this->assertSame(
			'',
			$this->service->assign(case: ['id' => 'c1', 'identifier' => 'BZW-2025-17'])
		);
	}//end testACaseThatAlreadyHasANumberIsNotTouched()

	/**
	 * A case without a number is given the next one and stored under its uuid.
	 *
	 * @return void
	 */
	public function testACaseWithoutANumberIsGivenTheNextOne(): void {
		$objectService = $this->createMock(CaseNumberObjectServiceStub::class);
		$objectService->method('searchObjectsBySlug')->willReturn([
			['id' => 'c0', 'identifier' => '2026-0041'],
			['id' => 'cx', 'identifier' => ''],
		]);

		$saved = [];
		$objectService->expects($this->once())
			->method('saveObject')
			->willReturnCallback(
				function (array $object, int|string $register, int|string $schema, ?string $uuid = null) use (&$saved): array {
					$saved = ['object' => $object, 'uuid' => $uuid];

					return $object;
				}
			);

		$this->settings->method('getObjectService')->willReturn($objectService);
		$this->settings->method('getConfigValue')->willReturn('dossiq');

		$number = $this->service->assign(
			case: ['id' => 'c1', 'title' => 'Aanvraag', 'startDate' => '2026-03-01']
		);

		$this->assertSame('2026-0042', $number);
		$this->assertSame('2026-0042', $saved['object']['identifier']);
		$this->assertSame('c1', $saved['uuid'], 'the number must be written to the case, not to a new one');
		$this->assertSame('Aanvraag', $saved['object']['title'], 'the rest of the case survives the write');
	}//end testACaseWithoutANumberIsGivenTheNextOne()

	/**
	 * A register that cannot be reached costs the case its number, not its life.
	 *
	 * @return void
	 */
	public function testAnUnreachableRegisterLeavesTheCaseStanding(): void {
		$this->settings->method('getObjectService')->willReturn(null);

		$this->assertSame('', $this->service->assign(case: ['id' => 'c1']));
	}//end testAnUnreachableRegisterLeavesTheCaseStanding()

	/**
	 * A failing write is logged and swallowed, never rethrown at case creation.
	 *
	 * @return void
	 */
	public function testAFailingWriteIsLoggedAndSwallowed(): void {
		$objectService = $this->createMock(CaseNumberObjectServiceStub::class);
		$objectService->method('searchObjectsBySlug')->willReturn([]);
		$objectService->method('saveObject')->willThrowException(new \RuntimeException('nope'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturn('dossiq');

		$service = new CaseNumberService(settingsService: $settings, logger: $logger);

		$this->assertSame('', $service->assign(case: ['id' => 'c1', 'startDate' => '2026-01-01']));
	}//end testAFailingWriteIsLoggedAndSwallowed()
}//end class
