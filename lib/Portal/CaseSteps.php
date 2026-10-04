<?php

/**
 * Dossiq Case Steps (site-resident-portal-design D3)
 *
 * "Waar staat uw aanvraag?" on a case in the portal: the public steps of the
 * case's own case type, which one the case sits in, and when it got there.
 *
 * The steps come from the CASE TYPE, never from the page. A case type's
 * statuses are its own and an administrator may add one; a page that listed
 * steps would then show a journey nobody walks. Consecutive statuses that
 * share one public label fold into one step, which is how eight internal
 * statuses read as five public ones: a resident is told "In behandeling"
 * once, not four times.
 *
 * The label is the status type's `publicLabel`, or its name when it declares
 * none, the rule of `citizen-status-labels`. The internal description is
 * never shown: it is written for a colleague.
 *
 * @category Portal
 * @package  OCA\Dossiq\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/site-resident-portal-design/specs/portal-contribution/spec.md#requirement-a-case-hands-the-portal-its-steps-req-srpd-003
 */

declare(strict_types=1);

namespace OCA\Dossiq\Portal;

use OCA\Dossiq\Service\Transitions\StatusPublicLabels;

/**
 * Folds a case type's statuses into the public steps of one case.
 *
 * @spec openspec/changes/site-resident-portal-design/specs/portal-contribution/spec.md#requirement-a-case-hands-the-portal-its-steps-req-srpd-003
 */
class CaseSteps {
	/**
	 * A step the case has passed.
	 */
	public const DONE = 'done';

	/**
	 * The step the case sits in.
	 */
	public const CURRENT = 'current';

	/**
	 * A step still to come.
	 */
	public const TODO = 'todo';

	/**
	 * The public steps of one case.
	 *
	 * @param array<string, mixed> $case The case row.
	 * @param array<int, array<string, mixed>> $statusTypes The case type's status types.
	 *
	 * @return array<int, array<string, string>> The steps, in order.
	 *
	 * @spec openspec/changes/site-resident-portal-design/specs/portal-contribution/spec.md#requirement-a-case-hands-the-portal-its-steps-req-srpd-003
	 */
	public function forCase(array $case, array $statusTypes): array {
		$folded = $this->fold(statusTypes: $this->ordered(statusTypes: $statusTypes));
		if ($folded === []) {
			return [];
		}

		$current = $this->currentIndex(folded: $folded, statusId: (string)($case['status'] ?? ''));
		$entered = $this->enteredAt(case: $case);
		$stopped = $this->hasStopped(case: $case);

		$steps = [];
		foreach ($folded as $index => $step) {
			// A case that stopped early (withdrawn, refused) never walks the
			// rest, so the steps after it are not promised as to do.
			if ($stopped === true && $current !== null && $index > $current) {
				break;
			}

			$steps[] = $this->entry(
				step: $step,
				state: $this->stateOf(index: $index, current: $current),
				entered: $entered,
				case: $case
			);
		}//end foreach

		return $steps;
	}//end forCase()

	/**
	 * Whether a step is done, the one the case is on, or still to come.
	 *
	 * @param int      $index   The step's place in the list.
	 * @param int|null $current The step the case is on, or null when unknown.
	 *
	 * @return string One of `done`, `current` or `todo`.
	 *
	 * @spec openspec/changes/site-resident-portal-design/specs/portal-contribution/spec.md#requirement-a-case-hands-the-portal-its-steps-req-srpd-003
	 */
	private function stateOf(int $index, ?int $current): string {
		if ($current === null) {
			return self::TODO;
		}

		if ($index < $current) {
			return self::DONE;
		}

		if ($index === $current) {
			return self::CURRENT;
		}

		return self::TODO;
	}//end stateOf()

	/**
	 * One step as the portal reads it: its label, its state, and the
	 * description and date when there are any.
	 *
	 * @param array{label: string, description: string, ids: array<int, string>} $step The folded step.
	 * @param string                                                             $state   Its state.
	 * @param array<string, string>                                              $entered The dates per status id.
	 * @param array<string, mixed>                                               $case    The case row.
	 *
	 * @return array<string, string> The step.
	 *
	 * @spec openspec/changes/site-resident-portal-design/specs/portal-contribution/spec.md#requirement-a-case-hands-the-portal-its-steps-req-srpd-003
	 */
	private function entry(array $step, string $state, array $entered, array $case): array {
		$entry = ['label' => $step['label'], 'state' => $state];
		if ($step['description'] !== '') {
			$entry['description'] = $step['description'];
		}

		$date = $this->dateOf(step: $step, entered: $entered, state: $state, case: $case);
		if ($date !== '') {
			$entry['date'] = $date;
		}

		return $entry;
	}//end entry()

	/**
	 * The status types in the order the case type declares, by `order`.
	 *
	 * @param array<int, array<string, mixed>> $statusTypes The rows.
	 *
	 * @return array<int, array<string, mixed>> The rows, ordered.
	 */
	private function ordered(array $statusTypes): array {
		$rows = [];
		foreach ($statusTypes as $row) {
			if (is_array($row) === true && $this->idOf(row: $row) !== '') {
				$rows[] = $row;
			}
		}

		usort(
			$rows,
			static function (array $first, array $second): int {
				return ((int)($first['order'] ?? 0) <=> (int)($second['order'] ?? 0));
			}
		);

		return $rows;
	}//end ordered()

	/**
	 * Consecutive statuses that share a public label become one step. The
	 * step keeps every status id it folded, so the case's own status can be
	 * found again, and the FIRST status's public description.
	 *
	 * @param array<int, array<string, mixed>> $statusTypes The ordered rows.
	 *
	 * @return array<int, array{label: string, description: string, ids: array<int, string>}> The steps.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) StatusPublicLabels::publicLabelOf()
	 * is the one reader of a status type's public label, and it is static so
	 * that the portal, the status page and this folding cannot come to
	 * disagree about what a status is called in public.
	 *
	 * @spec openspec/changes/site-resident-portal-design/specs/portal-contribution/spec.md#requirement-a-case-hands-the-portal-its-steps-req-srpd-003
	 */
	private function fold(array $statusTypes): array {
		$steps = [];
		foreach ($statusTypes as $row) {
			$label = StatusPublicLabels::publicLabelOf(statusType: $row);
			if ($label === '') {
				continue;
			}

			$last = (count($steps) - 1);
			if ($last >= 0 && $steps[$last]['label'] === $label) {
				$steps[$last]['ids'][] = $this->idOf(row: $row);
				continue;
			}

			$steps[] = [
				'label'       => $label,
				'description' => StatusPublicLabels::publicDescriptionOf(statusType: $row),
				'ids'         => [$this->idOf(row: $row)],
			];
		}//end foreach

		return $steps;
	}//end fold()

	/**
	 * Which step the case sits in, or null when its status is not one of
	 * them (a status without a public label, or a case type that changed).
	 *
	 * @param array<int, array<string, mixed>> $folded The folded steps.
	 * @param string $statusId The case's status.
	 *
	 * @return int|null The index.
	 */
	private function currentIndex(array $folded, string $statusId): ?int {
		if ($statusId === '') {
			return null;
		}

		foreach ($folded as $index => $step) {
			if (in_array($statusId, $step['ids'], true) === true) {
				return $index;
			}
		}

		return null;
	}//end currentIndex()

	/**
	 * When the case entered each status, from its own status history.
	 *
	 * @param array<string, mixed> $case The case row.
	 *
	 * @return array<string, string> The date per status id.
	 */
	private function enteredAt(array $case): array {
		$history = ($case['statusHistory'] ?? '');
		if (is_string($history) === true) {
			$history = json_decode($history, true);
		}

		if (is_array($history) === false) {
			return [];
		}

		$dates = [];
		foreach ($history as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$status = (string)($entry['status'] ?? ($entry['statusType'] ?? ($entry['to'] ?? '')));
			$moment = (string)($entry['enteredAt'] ?? ($entry['at'] ?? ($entry['date'] ?? '')));
			if ($status === '' || $moment === '' || isset($dates[$status]) === true) {
				continue;
			}

			$dates[$status] = $this->asDate(moment: $moment);
		}

		return $dates;
	}//end enteredAt()

	/**
	 * The date a step was reached: when the case entered the FIRST of the
	 * statuses it folded. The current step falls back to the case's own
	 * `currentStatusEnteredAt`, which every case carries.
	 *
	 * @param array{label: string, description: string, ids: array<int, string>} $step The step.
	 * @param array<string, string> $entered The date per status id.
	 * @param string $state The step's state.
	 * @param array<string, mixed> $case The case row.
	 *
	 * @return string The date, or ''.
	 */
	private function dateOf(array $step, array $entered, string $state, array $case): string {
		foreach ($step['ids'] as $id) {
			if (($entered[$id] ?? '') !== '') {
				return $entered[$id];
			}
		}

		if ($state === self::CURRENT) {
			return $this->asDate(moment: (string)($case['currentStatusEnteredAt'] ?? ''));
		}

		return '';
	}//end dateOf()

	/**
	 * Whether the case has stopped walking: it has an end date, or it sits at
	 * a final status.
	 *
	 * @param array<string, mixed> $case The case row.
	 *
	 * @return bool True when it has stopped.
	 */
	private function hasStopped(array $case): bool {
		if (trim((string)($case['endDate'] ?? '')) !== '') {
			return true;
		}

		return (($case['isFinalStatus'] ?? false) === true);
	}//end hasStopped()

	/**
	 * A moment as the day it fell on, or '' when it cannot be read.
	 *
	 * @param string $moment The moment.
	 *
	 * @return string The date, `Y-m-d`.
	 */
	private function asDate(string $moment): string {
		$trimmed = trim($moment);
		if ($trimmed === '') {
			return '';
		}

		$stamp = strtotime($trimmed);
		if ($stamp === false) {
			return '';
		}

		return gmdate('Y-m-d', $stamp);
	}//end asDate()

	/**
	 * A status type's id.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The id, or ''.
	 */
	private function idOf(array $row): string {
		foreach ([($row['id'] ?? null), ($row['uuid'] ?? null), (($row['@self'] ?? [])['id'] ?? null)] as $candidate) {
			if ((is_string($candidate) === true || is_int($candidate) === true) && (string)$candidate !== '') {
				return (string)$candidate;
			}
		}

		return '';
	}//end idOf()
}//end class
