<?php

/**
 * Unit tests for the Woo throughput report.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Woo;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Woo\WooThroughputReport;
use OCP\IConfig;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Assessments are counted per reviewer per day, by verdict, in the instance time zone.
 *
 * @covers \OCA\Dossiq\Woo\WooThroughputReport
 *
 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-throughput-per-reviewer-per-day-read-by-the-named-group-only-req-wrr-002
 */
class WooThroughputReportTest extends TestCase {

	/**
	 * The assessment store.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * Set up an empty store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
	}//end setUp()

	/**
	 * The report over the store, in a time zone.
	 *
	 * @param string $zone The instance time zone.
	 * @param bool $openRegister Whether OpenRegister answers.
	 *
	 * @return WooThroughputReport The report.
	 */
	private function report(string $zone = 'UTC', bool $openRegister = true): WooThroughputReport {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($openRegister === true ? $this->store : null);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (['register' => 'dossiq', 'woo_assessment_schema' => 'wooDocumentAssessment'][$key] ?? $default)
		);
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($key === 'default_timezone' ? $zone : $default)
		);
		$users = $this->createMock(IUserManager::class);
		$users->method('getDisplayName')->willReturnMap([['reviewer-a', 'Anna de Wit'], ['reviewer-b', null]]);

		return new WooThroughputReport(settingsService: $settings, config: $config, userManager: $users);
	}//end report()

	/**
	 * Seed one assessment.
	 *
	 * @param string $case The case.
	 * @param string $reviewer The reviewer.
	 * @param string $verdict The classification.
	 * @param string $at When it was assessed.
	 *
	 * @return void
	 */
	private function assessment(string $case, string $reviewer, string $verdict, string $at): void {
		static $n = 0;
		$n++;
		$this->store->seed(
			schema: 'wooDocumentAssessment',
			uuid: 'a-'.$n,
			row: ['caseRef' => $case, 'documentRef' => 'doc-'.$n, 'classification' => $verdict, 'assessedBy' => $reviewer, 'assessedAt' => $at]
		);
	}//end assessment()

	/**
	 * The scenario: A assessed 3 openbaar and 1 niet_openbaar, B 2 deels_openbaar, on 2026-11-10.
	 *
	 * @return void
	 */
	public function testAssessmentsAreCountedPerReviewerPerDay(): void {
		foreach (['09:00', '10:00', '11:00'] as $time) {
			$this->assessment(case: 'case-x', reviewer: 'reviewer-a', verdict: 'openbaar', at: '2026-11-10T'.$time.':00+00:00');
		}

		$this->assessment(case: 'case-x', reviewer: 'reviewer-a', verdict: 'niet_openbaar', at: '2026-11-10T12:00:00+00:00');
		$this->assessment(case: 'case-x', reviewer: 'reviewer-b', verdict: 'deels_openbaar', at: '2026-11-10T13:00:00+00:00');
		$this->assessment(case: 'case-x', reviewer: 'reviewer-b', verdict: 'deels_openbaar', at: '2026-11-10T14:00:00+00:00');
		// Another case, and a row without a reviewer, are not counted.
		$this->assessment(case: 'case-y', reviewer: 'reviewer-a', verdict: 'openbaar', at: '2026-11-10T09:00:00+00:00');
		$this->assessment(case: 'case-x', reviewer: '', verdict: 'openbaar', at: '2026-11-10T09:00:00+00:00');

		$report = $this->report()->forCase(caseId: 'case-x');

		$this->assertSame(
			[
				['reviewer' => 'reviewer-a', 'displayName' => 'Anna de Wit', 'day' => '2026-11-10', 'openbaar' => 3, 'deels_openbaar' => 0, 'niet_openbaar' => 1, 'total' => 4],
				['reviewer' => 'reviewer-b', 'displayName' => 'reviewer-b', 'day' => '2026-11-10', 'openbaar' => 0, 'deels_openbaar' => 2, 'niet_openbaar' => 0, 'total' => 2],
			],
			$report['rows']
		);
		$this->assertSame(['case-x'], $report['cases']);
		$this->assertFalse($report['truncated']);
	}//end testAssessmentsAreCountedPerReviewerPerDay()

	/**
	 * An assessment at 23:30 UTC on a CET instance lands on the next day.
	 *
	 * @return void
	 */
	public function testTheDayIsTheInstanceTimeZoneDay(): void {
		$this->assessment(case: 'case-x', reviewer: 'reviewer-a', verdict: 'openbaar', at: '2026-11-10T23:30:00Z');

		$this->assertSame('2026-11-11', $this->report(zone: 'Europe/Amsterdam')->forCase(caseId: 'case-x')['rows'][0]['day']);
		$this->assertSame('2026-11-10', $this->report(zone: 'UTC')->forCase(caseId: 'case-x')['rows'][0]['day']);
		$this->assertSame('2026-11-10', $this->report(zone: 'Not/AZone')->forCase(caseId: 'case-x')['rows'][0]['day']);
	}//end testTheDayIsTheInstanceTimeZoneDay()

	/**
	 * A period keeps its own days, both ends included, across cases.
	 *
	 * @return void
	 */
	public function testAPeriodKeepsOnlyItsDaysAcrossCases(): void {
		$this->assessment(case: 'case-x', reviewer: 'reviewer-a', verdict: 'openbaar', at: '2026-11-09T10:00:00Z');
		$this->assessment(case: 'case-x', reviewer: 'reviewer-a', verdict: 'openbaar', at: '2026-11-10T10:00:00Z');
		$this->assessment(case: 'case-y', reviewer: 'reviewer-a', verdict: 'openbaar', at: '2026-11-12T10:00:00Z');
		$this->assessment(case: 'case-z', reviewer: 'reviewer-a', verdict: 'openbaar', at: '2026-11-13T10:00:00Z');
		$this->assessment(case: 'case-z', reviewer: 'reviewer-a', verdict: 'openbaar', at: 'not a date');

		$report = $this->report()->forPeriod(from: '2026-11-10', to: '2026-11-12');

		$this->assertSame(['2026-11-10', '2026-11-12'], array_column($report['rows'], 'day'));
		$this->assertSame(['case-x', 'case-y'], $report['cases']);
	}//end testAPeriodKeepsOnlyItsDaysAcrossCases()

	/**
	 * A period that is not two dates is refused.
	 *
	 * @return void
	 */
	public function testAPeriodMustBeTwoDates(): void {
		$this->expectException(RefusedException::class);
		$this->report()->forPeriod(from: '', to: '2026-11-12');
	}//end testAPeriodMustBeTwoDates()

	/**
	 * Without OpenRegister the report is refused, not answered empty.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheReportIsRefusedNotEmpty(): void {
		try {
			$this->report(openRegister: false)->forCase(caseId: 'case-x');
			$this->fail('An empty report would read as nobody assessed anything');
		} catch (RefusedException $e) {
			$this->assertSame(503, $e->getStatus());
		}
	}//end testWithoutOpenRegisterTheReportIsRefusedNotEmpty()

	/**
	 * The CSV has a header and one line per row, and defuses a formula-like cell.
	 *
	 * @return void
	 */
	public function testTheCsvHoldsTheRowsAndDefusesFormulas(): void {
		$csv = WooThroughputReport::toCsv(
			rows: [['reviewer' => '=cmd', 'displayName' => 'A "B"', 'day' => '2026-11-10', 'openbaar' => 1, 'deels_openbaar' => 0, 'niet_openbaar' => 0, 'total' => 1]]
		);

		$this->assertSame(
			"\"reviewer\",\"displayName\",\"day\",\"openbaar\",\"deels_openbaar\",\"niet_openbaar\",\"total\"\r\n"
			."\"'=cmd\",\"A \"\"B\"\"\",\"2026-11-10\",\"1\",\"0\",\"0\",\"1\"\r\n",
			$csv
		);
	}//end testTheCsvHoldsTheRowsAndDefusesFormulas()
}//end class
