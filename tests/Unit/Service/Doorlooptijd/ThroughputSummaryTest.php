<?php

/**
 * Throughput summary unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Doorlooptijd
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Doorlooptijd;

use DateTimeImmutable;
use OCA\Dossiq\Service\Doorlooptijd\CaseTypeThroughputCalculator;
use OCA\Dossiq\Service\Doorlooptijd\DeadlineComplianceCalculator;
use PHPUnit\Framework\TestCase;

/**
 * The summary figures the processing-time page shows above its charts.
 *
 * @covers \OCA\Dossiq\Service\Doorlooptijd\DeadlineComplianceCalculator
 * @covers \OCA\Dossiq\Service\Doorlooptijd\CaseTypeThroughputCalculator
 * @uses \OCA\Dossiq\Service\Support\LanguageMapText
 *
 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
 */
class ThroughputSummaryTest extends TestCase {

	/**
	 * One enriched case as the enricher leaves it.
	 *
	 * @param string      $caseType The case type id.
	 * @param string|null $end      The end date, null while open.
	 * @param int|null    $days     The throughput days.
	 * @param string|null $deadline The deadline.
	 *
	 * @return array<string, mixed>
	 */
	private function closed(string $caseType, ?string $end, ?int $days, ?string $deadline): array {
		return [
			'caseType' => $caseType,
			'_isOpen' => $end === null,
			'_endDate' => $end,
			'_throughputDays' => $days,
			'_deadline' => $deadline,
		];
	}//end closed()

	/**
	 * The average, the year count and the months come from closed cases only.
	 *
	 * @return void
	 */
	public function testSummaryAveragesClosedCasesPerMonth(): void {
		$cases = [
			$this->closed('woo', '2026-09-10', 20, '2026-09-30'),
			$this->closed('woo', '2026-09-20', 30, '2026-09-15'),
			$this->closed('bezwaar', '2026-08-05', 10, '2026-09-01'),
			$this->closed('bezwaar', '2025-06-01', 99, '2025-07-01'),
			$this->closed('woo', null, null, '2026-11-01'),
		];

		$summary = (new DeadlineComplianceCalculator())->computeSummary($cases, '3m', new DateTimeImmutable('2026-10-16'));

		self::assertSame(20, $summary['avgDays']);
		self::assertSame(3, $summary['closedThisYear']);
		self::assertSame(['2026-08', '2026-09', '2026-10'], $summary['monthlyAvgDays']['labels']);
		self::assertSame([10, 25, null], $summary['monthlyAvgDays']['values']);
	}//end testSummaryAveragesClosedCasesPerMonth()

	/**
	 * The share within the norm is counted per case type, by its title.
	 *
	 * @return void
	 */
	public function testWithinNormIsSharedPerCaseType(): void {
		$cases = [
			$this->closed('woo', '2026-09-10', 20, '2026-09-30'),
			$this->closed('woo', '2026-09-20', 30, '2026-09-15'),
			$this->closed('bezwaar', '2026-08-05', 10, '2026-09-01'),
			$this->closed('bezwaar', '2026-08-06', 11, null),
		];
		$caseTypes = [
			['id' => 'woo', 'title' => 'Woo-verzoek'],
			['id' => 'bezwaar', 'title' => 'Bezwaar'],
		];

		$shares = (new CaseTypeThroughputCalculator())->computeWithinNormByCaseType($cases, $caseTypes);

		self::assertSame(['Woo-verzoek', 'Bezwaar'], $shares['labels']);
		self::assertSame([50, 100], $shares['shares']);

		$busiest = (new CaseTypeThroughputCalculator())->computeWithinNormByCaseType($cases, $caseTypes, 1);
		self::assertSame(['Woo-verzoek'], $busiest['labels']);
	}//end testWithinNormIsSharedPerCaseType()
}//end class
