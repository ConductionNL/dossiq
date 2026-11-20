<?php

/**
 * Dossiq process mining case status records.
 *
 * The status records of the cases in a process mining report, grouped by
 * case and put in time order; a case without records is read from its own
 * status history.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\ProcessMining
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

namespace OCA\Dossiq\Service\ProcessMining;

use DateTimeImmutable;
use OCA\Dossiq\Service\CaseDateNormaliser;

/**
 * Status records per case, in time order; reads no store.
 *
 * @spec openspec/changes/archive/2026-07-14-process-mining-bottlenecks/tasks.md#T01
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class CaseStatusRecords {

	/**
	 * Constructor.
	 *
	 * @param CaseDateNormaliser $dates The one date path.
	 */
	public function __construct(
		private readonly CaseDateNormaliser $dates,
	) {
	}//end __construct()

	/**
	 * Group and chronologically sort statusRecords by their `case` field,
	 * restricted to the cases in scope; a case without records is read from
	 * its own status history.
	 *
	 * @param array<int, array<string, mixed>>    $records Raw statusRecord rows.
	 * @param array<string, array<string, mixed>> $cases   The cases in scope, by id.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 *
	 * @spec openspec/changes/archive/2026-07-14-process-mining-bottlenecks/tasks.md#T01
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function byCase(array $records, array $cases): array {
		$grouped = self::recordsOfKnownCases(records: $records, cases: $cases);

		// A case without status records (example data, an imported case) is read from its own status history.
		foreach (array_diff_key($cases, $grouped) as $caseId => $case) {
			$fromHistory = $this->recordsFromHistory(caseId: (string)$caseId, case: $case);
			if ($fromHistory !== []) {
				$grouped[$caseId] = $fromHistory;
			}
		}

		foreach ($grouped as $caseId => $rows) {
			$grouped[$caseId] = $this->inTimeOrder(rows: $rows);
		}

		return $grouped;
	}//end byCase()

	/**
	 * The status records grouped by the case they belong to, for the known cases only.
	 *
	 * @param array<int, array<string, mixed>>   $records The status records.
	 * @param array<string, array<string, mixed>> $cases   The cases by id.
	 *
	 * @return array<string, array<int, array<string, mixed>>> The records by case id.
	 */
	private static function recordsOfKnownCases(array $records, array $cases): array {
		$grouped = [];
		foreach ($records as $record) {
			$caseId = (string)($record['case'] ?? '');
			if ($caseId !== '' && isset($cases[$caseId]) === true) {
				$grouped[$caseId][] = $record;
			}
		}

		return $grouped;
	}//end recordsOfKnownCases()

	/**
	 * One case's status records sorted by their timestamp; records without one keep their place.
	 *
	 * @param array<int, array<string, mixed>> $rows The records.
	 *
	 * @return array<int, array<string, mixed>> The sorted records.
	 */
	private function inTimeOrder(array $rows): array {
		usort(
			$rows,
			function (array $left, array $right): int {
				$leftAt = $this->extractTimestamp(record: $left);
				$rightAt = $this->extractTimestamp(record: $right);
				if ($leftAt === null || $rightAt === null) {
					return 0;
				}

				return ($leftAt <=> $rightAt);
			}
		);

		return $rows;
	}//end inTimeOrder()

	/**
	 * Status records read off a case's own status history: one per entry, at the moment it entered that status.
	 *
	 * @param string               $caseId The case id.
	 * @param array<string, mixed> $case   The case; `statusHistory` as a list or as JSON text.
	 *
	 * Each is held by the person the entry names, else by the case's handler:
	 * a history entry carries no actor of its own, and time nobody holds would
	 * read as "not recorded" in the per-handler table.
	 *
	 * @return array<int, array{case: string, statusType: string, createdAt: string, actor: string}>
	 *
	 * @spec openspec/specs/dwell-from-status-history-actor/spec.md#requirement-time-read-from-the-status-history-has-a-holder
	 */
	private function recordsFromHistory(string $caseId, array $case): array {
		$history = ($case['statusHistory'] ?? []);
		if (is_string($history) === true) {
			$history = json_decode($history, true);
		}

		$origin = ['caseId' => $caseId, 'holder' => trim((string)($case['assignee'] ?? ''))];
		$records = [];
		foreach ((array)$history as $entry) {
			$record = $this->recordOf(entry: $entry, origin: $origin);
			if ($record !== null) {
				$records[] = $record;
			}
		}

		return $records;
	}//end recordsFromHistory()

	/**
	 * One status history entry as a process record, null when it names no status or moment.
	 *
	 * @param mixed                                 $entry  The history entry.
	 * @param array{caseId: string, holder: string} $origin The case uuid, and the case's assignee (the actor when the entry names none).
	 *
	 * @return array{case: string, statusType: string, createdAt: string, actor: string}|null
	 */
	private function recordOf(mixed $entry, array $origin): ?array {
		if (is_array($entry) === false) {
			return null;
		}

		$status = (string)($entry['status'] ?? '');
		$moment = (string)($entry['enteredAt'] ?? '');
		if ($status === '' || $moment === '') {
			return null;
		}

		return [
			'case' => $origin['caseId'],
			'statusType' => $status,
			'createdAt' => $moment,
			'actor' => $this->actorOf(entry: $entry, holder: $origin['holder']),
		];
	}//end recordOf()

	/**
	 * Who made a history entry: its actor or by, else the case's assignee.
	 *
	 * @param array<string, mixed> $entry  The history entry.
	 * @param string               $holder The case's assignee.
	 *
	 * @return string
	 */
	private function actorOf(array $entry, string $holder): string {
		$actor = trim((string)($entry['actor'] ?? ($entry['by'] ?? '')));
		if ($actor === '') {
			$actor = $holder;
		}

		return $actor;
	}//end actorOf()

	/**
	 * Extract a record's creation timestamp — either the flattened
	 * `createdAt` key or OpenRegister's `@self.created` metadata block.
	 *
	 * @param array<string, mixed> $record A statusRecord row.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function extractTimestamp(array $record): ?DateTimeImmutable {
		$raw = ($record['createdAt'] ?? ($record['@self']['created'] ?? ($record['@self']['createdAt'] ?? null)));
		if (is_string($raw) === false || $raw === '') {
			return null;
		}

		return $this->dates->tryParse($raw);
	}//end extractTimestamp()
}//end class
