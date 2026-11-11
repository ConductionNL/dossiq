<?php

/**
 * Characterisation tests for which assessments WooThroughputReport counts:
 * without a reviewer, a day or a known verdict they count nowhere, and a
 * period keeps its first and last day.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Woo;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCA\Dossiq\Woo\WooThroughputReport;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

class WooThroughputCountableGoldenTest extends TestCase {

	use MakesCaseDateNormaliser;

	private InMemoryRegister $store;

	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$rows = [
			['caseRef' => 'c1', 'assessedBy' => ' anna ', 'classification' => 'openbaar', 'assessedAt' => '2026-03-01T10:00:00Z'],
			['caseRef' => 'c1', 'assessedBy' => 'anna', 'classification' => 'niet_openbaar', 'assessedAt' => '2026-03-31T10:00:00Z'],
			['caseRef' => 'c2', 'assessedBy' => 'anna', 'classification' => 'openbaar', 'assessedAt' => '2026-02-28T23:00:00Z'],
			['caseRef' => 'c3', 'assessedBy' => 'anna', 'classification' => 'openbaar', 'assessedAt' => '2026-04-01T00:00:00Z'],
			['caseRef' => 'c4', 'assessedBy' => '', 'classification' => 'openbaar', 'assessedAt' => '2026-03-10T10:00:00Z'],
			['caseRef' => 'c5', 'assessedBy' => 'bob', 'classification' => 'onbekend', 'assessedAt' => '2026-03-10T10:00:00Z'],
			['caseRef' => 'c6', 'assessedBy' => 'bob', 'classification' => 'openbaar', 'assessedAt' => 'never'],
			['caseRef' => 'c7', 'assessedBy' => 'bob', 'classification' => 'deels_openbaar'],
		];
		foreach ($rows as $i => $row) {
			$this->store->seed(schema: 'wooDocumentAssessment', uuid: 'a-' . $i, row: $row + ['documentRef' => 'd-' . $i]);
		}
	}//end setUp()

	private function report(): WooThroughputReport {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (['register' => 'dossiq', 'woo_assessment_schema' => 'wooDocumentAssessment'][$key] ?? $default)
		);

		return new WooThroughputReport(settingsService: $settings, caseDates: $this->caseDates(zone: 'UTC'), userManager: $this->createMock(IUserManager::class));
	}//end report()

	private function summary(array $report): array {
		return [
			array_map(static fn (array $row): string => $row['reviewer'] . '@' . $row['day'] . ':' . $row['total'], $report['rows']),
			$report['cases'],
		];
	}//end summary()

	public function testAPeriodCountsItsFirstAndLastDayOnly(): void {
		self::assertSame(
			[['anna@2026-03-01:1', 'anna@2026-03-31:1'], ['c1']],
			$this->summary($this->report()->forPeriod('2026-03-01', '2026-03-31'))
		);
	}//end testAPeriodCountsItsFirstAndLastDayOnly()

	public function testWithoutAPeriodOnlyTheUncountableAreLeftOut(): void {
		self::assertSame(
			[['anna@2026-03-01:1', 'anna@2026-03-31:1'], ['c1']],
			$this->summary($this->report()->forCase('c1'))
		);
		self::assertSame([[], []], $this->summary($this->report()->forCase('c4')));
		self::assertSame([[], []], $this->summary($this->report()->forCase('c5')));
		self::assertSame([[], []], $this->summary($this->report()->forCase('c6')));
		self::assertSame([[], []], $this->summary($this->report()->forCase('c7')));
		self::assertSame([['anna@2026-04-01:1'], ['c3']], $this->summary($this->report()->forCase('c3')));
	}//end testWithoutAPeriodOnlyTheUncountableAreLeftOut()
}//end class
