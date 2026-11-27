<?php

/**
 * Dossiq recorded report read.
 *
 * Reads the throughput report for one case or a period and records the read
 * on the audit trail of every case it counted, before anything is answered.
 * A read that cannot be made or recorded comes back as a refusal, never as a
 * report. Split out of {@see \OCA\Dossiq\Controller\WooReportController},
 * which keeps the session, the switch and group checks, the refusal sentences
 * and the JSON or CSV answer.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/woo-review-reports/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Kernel\Contracts\CaseDateNormaliserInterface;
use OCA\Dossiq\Kernel\Contracts\WooReportReadLogInterface;
use OCA\Dossiq\Kernel\Contracts\WooThroughputReportInterface;

/**
 * One read of the report, recorded before it is answered.
 *
 * @spec openspec/specs/woo-review-reports/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class RecordedReportRead {

	/**
	 * Constructor.
	 *
	 * @param WooThroughputReportInterface     $report  The throughput report.
	 * @param WooReportReadLogInterface        $readLog The read log on the audit trail.
	 * @param CaseDateNormaliserInterface|null $dates   Reads a moment as the tenant's calendar date.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly WooThroughputReportInterface $report,
		private readonly WooReportReadLogInterface $readLog,
		private readonly ?CaseDateNormaliserInterface $dates = null,
	) {
	}//end __construct()

	/**
	 * What is read: the named case, or else the period as the tenant's calendar dates.
	 *
	 * @param string $caseId The case, or ''.
	 * @param string $from   The first day of the period, as it arrived.
	 * @param string $to     The last day of the period, as it arrived.
	 *
	 * @return array<string, string> `{case}` or `{from, to}`.
	 *
	 * @spec openspec/specs/woo-review-reports/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function scope(string $caseId, string $from, string $to): array {
		if ($caseId !== '') {
			return ['case' => $caseId];
		}

		return ['from' => $this->calendarDate(value: $from), 'to' => $this->calendarDate(value: $to)];
	}//end scope()

	/**
	 * Read the report for the scope and record the read; a refusal instead when either fails.
	 *
	 * A read that cannot be recorded is not answered.
	 *
	 * @param string                $readerId The reader.
	 * @param array<string, string> $scope    The case or the period.
	 *
	 * @return array{report: array<string, mixed>|null, refusal: array{rule: string, status: int}|null}
	 *
	 * @spec openspec/specs/woo-review-reports/spec.md#requirement-throughput-per-reviewer-per-day-read-by-the-named-group-only-req-wrr-002
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function read(string $readerId, array $scope): array {
		try {
			$report = $this->reportFor(scope: $scope);
			$this->readLog->recordThroughputRead(readerId: $readerId, scope: $scope, caseIds: $report['cases']);
		} catch (RefusedException $e) {
			return ['report' => null, 'refusal' => ['rule' => $e->getRule(), 'status' => $e->getStatus()]];
		}

		return ['report' => $report, 'refusal' => null];
	}//end read()

	/**
	 * A period bound as the tenant's calendar date. A dashboard date range
	 * sends moments (`2026-10-01T22:00:00.000Z`); the report counts days, so
	 * a moment is read as the day it falls on in the tenant's zone. A plain
	 * date, or anything else, passes as given and the report judges it.
	 *
	 * @param string $value The bound as it arrived.
	 *
	 * @return string The bound as `Y-m-d`, or the value unchanged.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function calendarDate(string $value): string {
		if ($this->dates === null || preg_match('/^\d{4}-\d{2}-\d{2}T/', $value) !== 1) {
			return $value;
		}

		return ($this->dates->toCalendarDateOrNull(value: $value) ?? $value);
	}//end calendarDate()

	/**
	 * Read one case, or a period when no case is named.
	 *
	 * @param array<string, string> $scope The case or the period.
	 *
	 * @return array{rows: list<array<string, int|string>>, cases: list<string>, truncated: bool} The report.
	 *
	 * @throws RefusedException When the report cannot be made.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function reportFor(array $scope): array {
		if (isset($scope['case']) === true) {
			return $this->report->forCase(caseId: $scope['case']);
		}

		return $this->report->forPeriod(from: $scope['from'], to: $scope['to']);
	}//end reportFor()
}//end class
