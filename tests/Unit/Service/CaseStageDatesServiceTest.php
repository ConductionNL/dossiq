<?php

/**
 * Dossiq Case Stage Dates Service Test
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CaseStageDatesService;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * The first day a case reached each status.
 */
final class CaseStageDatesServiceTest extends TestCase {
	/**
	 * A service over a settings double that has no object store.
	 *
	 * @return CaseStageDatesService The service.
	 */
	private function service(): CaseStageDatesService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);

		return new CaseStageDatesService($settings);
	}//end service()

	/**
	 * The first day wins when the case came back to a status.
	 *
	 * @return void
	 */
	public function testAStatusKeepsTheFirstDayTheCaseGotThere(): void {
		$dates = $this->service()->foldRecords([
			['statusType' => 's1', 'createdAt' => '2026-10-07T10:00:00+00:00'],
			['statusType' => 's2', 'createdAt' => '2026-10-09T10:00:00+00:00'],
			['statusType' => 's1', 'createdAt' => '2026-10-05T08:00:00+00:00'],
			['statusType' => 's3', '@self' => ['createdAt' => '2026-10-11T08:00:00+00:00']],
		]);

		$this->assertSame(['s1' => '2026-10-05', 's2' => '2026-10-09', 's3' => '2026-10-11'], $dates);
	}//end testAStatusKeepsTheFirstDayTheCaseGotThere()

	/**
	 * Moves with no status or no readable day are left out.
	 *
	 * @return void
	 */
	public function testUnreadableMovesAreLeftOut(): void {
		$dates = $this->service()->foldRecords([
			['statusType' => '', 'createdAt' => '2026-10-05T08:00:00+00:00'],
			['statusType' => 's1', 'createdAt' => 'not-a-date'],
			['statusType' => 's2'],
		]);

		$this->assertSame([], $dates);
	}//end testUnreadableMovesAreLeftOut()

	/**
	 * Without an object store the answer is none, not an error.
	 *
	 * @return void
	 */
	public function testWithoutAStoreThereAreNoDates(): void {
		$this->assertSame([], $this->service()->reachedDatesFor('case-1'));
	}//end testWithoutAStoreThereAreNoDates()
}//end class
