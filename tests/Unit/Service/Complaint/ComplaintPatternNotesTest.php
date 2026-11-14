<?php

/**
 * Unit tests for ComplaintPatternNotes: each complaint KPI is shown with its
 * target and whether the quarter is on, above or below it, and a KPI nobody
 * could be measured on says so.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Complaint;

use OCA\Dossiq\Service\Complaint\ComplaintPatternNotes;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Complaint\ComplaintPatternNotes
 */
class ComplaintPatternNotesTest extends TestCase {

	private const TARGETS = [
		'complaint_target_per_month' => 10,
		'complaint_target_within_awb_percent' => 90,
		'complaint_target_upheld_percent' => 25,
	];

	private function notes(): ComplaintPatternNotes {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		return new ComplaintPatternNotes($l10n);
	}//end notes()

	private function figures(int $count, ?int $within, ?int $upheld, ?int $days): array {
		return ['count' => $count, 'handling' => ['withinAwbPercent' => $within, 'upheldPercent' => $upheld, 'averageDays' => $days]];
	}//end figures()

	public function testEachKpiIsJudgedAgainstItsTarget(): void {
		$this->assertSame(
			[
				'perMonth' => 10,
				'perMonthNote' => 'Target: at most 10. On target',
				'withinAwbPercent' => 85,
				'withinAwbNote' => 'Target: more than 90 %. Below target',
				'upheldPercent' => 20,
				'upheldNote' => 'Target: less than 25 %. On target',
				'averageDays' => 50,
				'averageDaysNote' => 'Awb term 6 weeks (42 d). Above target',
			],
			$this->notes()->kpis($this->figures(31, 85, 20, 50), self::TARGETS)
		);
	}//end testEachKpiIsJudgedAgainstItsTarget()

	public function testTheBoundariesFallOnTheRightSide(): void {
		$kpis = $this->notes()->kpis($this->figures(32, 90, 25, 42), self::TARGETS);

		$this->assertSame(11, $kpis['perMonth']);
		$this->assertSame('Target: at most 10. Above target', $kpis['perMonthNote']);
		$this->assertSame('Target: more than 90 %. Below target', $kpis['withinAwbNote']);
		$this->assertSame('Target: less than 25 %. Above target', $kpis['upheldNote']);
		$this->assertSame('Awb term 6 weeks (42 d). On target', $kpis['averageDaysNote']);
	}//end testTheBoundariesFallOnTheRightSide()

	public function testAQuarterWithNothingHandledSaysSo(): void {
		$kpis = $this->notes()->kpis($this->figures(0, null, null, null), self::TARGETS);

		$this->assertSame(0, $kpis['perMonth']);
		$this->assertSame('Target: at most 10. On target', $kpis['perMonthNote']);
		$this->assertNull($kpis['withinAwbPercent']);
		$this->assertSame('No complaint was handled this quarter', $kpis['withinAwbNote']);
		$this->assertSame('No complaint was handled this quarter', $kpis['upheldNote']);
		$this->assertSame('No complaint was handled this quarter', $kpis['averageDaysNote']);
	}//end testAQuarterWithNothingHandledSaysSo()
}//end class
