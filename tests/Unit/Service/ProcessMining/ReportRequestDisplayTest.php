<?php

/**
 * Locks the display and period checks of a process-mining report request.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\ProcessMining;

use DateTimeImmutable;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\ProcessMining\ReportRequest;
use OCA\Dossiq\Service\Reporting\ReportPeriod;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * An unknown period or view is refused by name, and no period means no start date.
 */
#[CoversClass(ReportRequest::class)]
#[UsesClass(ReportPeriod::class)]
final class ReportRequestDisplayTest extends TestCase {

	private ReportRequest $requests;

	protected function setUp(): void {
		$dates = $this->createMock(CaseDateNormaliser::class);
		$dates->method('today')->willReturn(new DateTimeImmutable('2026-06-15'));
		$this->requests = new ReportRequest(periods: new ReportPeriod(), dates: $dates);
	}//end setUp()

	/**
	 * A valid download query with the given overrides.
	 *
	 * @param array<string, mixed> $set Overrides.
	 *
	 * @return array<string, mixed>
	 */
	private function query(array $set): array {
		return [...['from' => null, 'to' => null, 'caseType' => null, 'period' => null, 'view' => null, 'format' => 'json', 'statistic' => 'mean'], ...$set];
	}//end query()

	public function testAnUnknownPeriodIsRefusedWithTheChoices(): void {
		$this->assertSame(
			'period must be one of 1m, 3m, 6m, 12m, year, all',
			$this->requests->refusal(query: $this->query(['period' => 'decade']))
		);
	}//end testAnUnknownPeriodIsRefusedWithTheChoices()

	public function testAnUnknownViewIsRefusedWithTheChoices(): void {
		$this->assertSame(
			'view must be one of summary, dwell, throughput, bottlenecks, byAssignee',
			$this->requests->refusal(query: $this->query(['view' => 'gantt']))
		);
	}//end testAnUnknownViewIsRefusedWithTheChoices()

	public function testThePeriodIsRefusedBeforeTheView(): void {
		$this->assertStringStartsWith('period', (string)$this->requests->refusal(query: $this->query(['period' => 'decade', 'view' => 'gantt'])));
	}//end testThePeriodIsRefusedBeforeTheView()

	public function testEveryKnownViewAndAnEmptyPeriodAreAccepted(): void {
		foreach (['summary', 'dwell', 'throughput', 'bottlenecks', 'byAssignee', ''] as $view) {
			$this->assertNull($this->requests->refusal(query: $this->query(['view' => $view, 'period' => ''])));
		}
	}//end testEveryKnownViewAndAnEmptyPeriodAreAccepted()

	public function testNoPeriodAndNoFromMeansNoStartDate(): void {
		$this->assertSame([], $this->requests->filters(query: $this->query([])));
		$this->assertSame(['to' => '2026-06-01'], $this->requests->filters(query: $this->query(['to' => '2026-06-01', 'period' => ''])));
	}//end testNoPeriodAndNoFromMeansNoStartDate()
}//end class
