<?php

/**
 * Dossiq complaint deadline alerts.
 *
 * Which active complaints are past their handling deadline or close to it,
 * and the overdue list an assistant may see, rebuilt from an allowlist so the
 * complainant never leaves.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Complaint
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Complaint;

use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\Term\DayCount;

/**
 * Sorts complaint rows by their deadlines; reads no store.
 *
 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-02
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class ComplaintDeadlineAlerts {

	/**
	 * Fields of a complaint an assistant may see in a list (REQ-MCP-203).
	 *
	 * `complainant` is the citizen and is never one of them.
	 *
	 * @var array<string>
	 */
	private const OVERDUE_PROJECTION = [
		'id',
		'complaintNumber',
		'subject',
		'category',
		'status',
		'handler',
		'acknowledgementOfReceiptDeadline',
		'afhandelDeadline',
	];

	/**
	 * Constructor.
	 *
	 * @param CaseDateNormaliser $dates The one date path, and today.
	 * @param DayCount $days Counts the days between two moments.
	 */
	public function __construct(
		private readonly CaseDateNormaliser $dates,
		private readonly DayCount $days = new DayCount(),
	) {
	}//end __construct()

	/**
	 * The complaints past their handling deadline, and those within the window.
	 *
	 * The window is counted in calendar days. A complaint without a readable
	 * deadline is in neither group.
	 *
	 * @param array<int, array<string, mixed>> $complaints The active complaints.
	 * @param int $warningDays Warn when the deadline is within this many days.
	 *
	 * @return array<string, array<int, array<string, mixed>>> overdue and warning.
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-02
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function grouped(array $complaints, int $warningDays): array {
		$today = $this->dates->today();
		$overdue = [];
		$warning = [];

		foreach ($complaints as $complaint) {
			$deadlineDate = $this->dates->tryParse($complaint['afhandelDeadline'] ?? null);
			if ($deadlineDate === null) {
				continue;
			}

			$diff = $this->days->between(from: $today, to: $deadlineDate);
			$isPast = $today > $deadlineDate;

			if ($isPast === true) {
				$overdue[] = $complaint;
			} elseif ($diff <= $warningDays) {
				$warning[] = $complaint;
			}
		}

		return ['overdue' => $overdue, 'warning' => $warning];
	}//end grouped()

	/**
	 * The overdue complaints as an assistant may see them, without the complainant.
	 *
	 * A row is rebuilt from an allowlist rather than stripped of a denylist, so
	 * a field added to the schema later stays out until someone names it.
	 *
	 * @param array<int, array<string, mixed>> $overdue The overdue complaints.
	 *
	 * @return array{complaints: list<array<string, mixed>>, count: int}
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-203-overdue-complaints-via-redacted-projection
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function overdueRows(array $overdue): array {
		$today = $this->dates->today();
		$rows = [];
		foreach ($overdue as $complaint) {
			$row = [];
			foreach (self::OVERDUE_PROJECTION as $field) {
				if (array_key_exists($field, $complaint) === true) {
					$row[$field] = $complaint[$field];
				}
			}

			$ackDeadline = $this->dates->tryParse($complaint['acknowledgementOfReceiptDeadline'] ?? null);
			$row['handlingOverdue'] = true;
			$row['acknowledgementOverdue'] = $ackDeadline !== null
				&& $today > $ackDeadline
				&& ($complaint['status'] ?? null) === 'received';
			$rows[] = $row;
		}

		return ['complaints' => $rows, 'count' => count($rows)];
	}//end overdueRows()
}//end class
