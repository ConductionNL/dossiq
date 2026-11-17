<?php

/**
 * Complaint deadline alerts tests
 *
 * Which complaints are overdue or close to their handling deadline, and the
 * overdue list an assistant may see.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Complaint
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Complaint;

use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\Complaint\ComplaintDeadlineAlerts;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * The deadline groups and the redacted overdue rows.
 *
 * @covers \OCA\Dossiq\Service\Complaint\ComplaintDeadlineAlerts
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses \OCA\Dossiq\Service\Term\DayCount
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class ComplaintDeadlineAlertsTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The frozen date path the alerts and the fixtures share.
	 *
	 * @var CaseDateNormaliser
	 */
	private CaseDateNormaliser $dates;

	/**
	 * The alerts under test.
	 *
	 * @var ComplaintDeadlineAlerts
	 */
	private ComplaintDeadlineAlerts $alerts;

	/**
	 * Set up the alerts over a frozen clock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->dates = $this->caseDatesFrozenAt();
		$this->alerts = new ComplaintDeadlineAlerts(dates: $this->dates);
	}//end setUp()

	/**
	 * A date relative to the frozen today.
	 *
	 * @param string $shift A DateTime modifier.
	 *
	 * @return string The calendar date.
	 */
	private function day(string $shift): string {
		return $this->dates->today()->modify($shift)->format('Y-m-d');
	}//end day()

	/**
	 * Yesterday is overdue, today and the window's last day warn, the day after does not.
	 *
	 * @return void
	 */
	public function testGroupsByTheWarningWindowInclusive(): void {
		$result = $this->alerts->grouped(
			complaints: [
				['id' => 'late', 'afhandelDeadline' => $this->day('-1 day')],
				['id' => 'today', 'afhandelDeadline' => $this->day('+0 days')],
				['id' => 'edge', 'afhandelDeadline' => $this->day('+5 days')],
				['id' => 'out', 'afhandelDeadline' => $this->day('+6 days')],
				['id' => 'none'],
				['id' => 'garbled', 'afhandelDeadline' => 'soon'],
			],
			warningDays: 5
		);

		$this->assertSame(['late'], array_column($result['overdue'], 'id'));
		$this->assertSame(['today', 'edge'], array_column($result['warning'], 'id'));
	}//end testGroupsByTheWarningWindowInclusive()

	/**
	 * The rows keep the allowlist, in its order, and drop everything else.
	 *
	 * @return void
	 */
	public function testOverdueRowsKeepOnlyTheAllowlistAndFlagALateAcknowledgement(): void {
		$result = $this->alerts->overdueRows(
			overdue: [
				[
					'afhandelDeadline' => $this->day('-1 day'),
					'complainant' => ['naam' => 'X'],
					'status' => 'received',
					'id' => 'k-1',
					'acknowledgementOfReceiptDeadline' => $this->day('-2 days'),
				],
			]
		);

		$this->assertSame(
			[
				'complaints' => [
					[
						'id' => 'k-1',
						'status' => 'received',
						'acknowledgementOfReceiptDeadline' => $this->day('-2 days'),
						'afhandelDeadline' => $this->day('-1 day'),
						'handlingOverdue' => true,
						'acknowledgementOverdue' => true,
					],
				],
				'count' => 1,
			],
			$result
		);
	}//end testOverdueRowsKeepOnlyTheAllowlistAndFlagALateAcknowledgement()

	/**
	 * No overdue complaints is an empty list with a zero count.
	 *
	 * @return void
	 */
	public function testNoOverdueComplaintsIsAnEmptyList(): void {
		$this->assertSame(['complaints' => [], 'count' => 0], $this->alerts->overdueRows(overdue: []));
	}//end testNoOverdueComplaintsIsAnEmptyList()
}//end class
