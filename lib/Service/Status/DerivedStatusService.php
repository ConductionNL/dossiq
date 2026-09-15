<?php

/**
 * Which status a case is now in, when the case type says so rather than a
 * person.
 *
 * Two of the statuses handlers set most often are not judgments at all. A case
 * is "complete" when the form and the documents are there, and it is not
 * complete when one of them is missing. Asking somebody to set that by hand
 * means the status is right only as often as they remember.
 *
 * So a status type may declare its conditions, and this service answers two
 * questions about them: which declared status now holds, and, for the one that
 * does not, what is missing. The second question is the one that earns the
 * change. A "Complete" that never arrives, with no reason on the case, is
 * worse than no derivation at all.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Status
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
 *
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Status;

use OCA\Dossiq\Service\CaseTypeResolver;

/**
 * Resolves the derived status of a case and the reasons a derivation has not fired.
 *
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */
class DerivedStatusService {

	/**
	 * Constructor.
	 *
	 * @param CaseTypeResolver      $caseTypes  The effective blueprint, so an inherited status counts too.
	 * @param StatusDeclaration     $declaration What a status says about itself.
	 * @param DerivedStatusEvaluator $evaluator  Whether a declaration holds.
	 */
	public function __construct(
		private readonly CaseTypeResolver $caseTypes,
		private readonly StatusDeclaration $declaration,
		private readonly DerivedStatusEvaluator $evaluator,
	) {
	}//end __construct()

	/**
	 * Every derived status of this case's type, with its verdict.
	 *
	 * Ordered by the status's own `order`, so "the furthest the case has got"
	 * is the LAST satisfied one rather than whichever the store listed first.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<int, array{statusId: string, name: string, order: int, satisfied: bool, unmet: array<int, string>}>
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	public function verdictsFor(array $case): array {
		$caseTypeId = (string)($case['caseType'] ?? '');
		if ($caseTypeId === '') {
			return [];
		}

		$verdicts = [];
		foreach ($this->caseTypes->statusTypesFor(caseTypeId: $caseTypeId) as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$statusId = (string)($row['id'] ?? ($row['uuid'] ?? ''));
			if ($statusId === '' || $this->declaration->isDerived(statusType: $row) === false) {
				continue;
			}

			$verdict = $this->evaluator->evaluate(statusType: $row, case: $case);
			$verdicts[] = [
				'statusId' => $statusId,
				'name' => (string)($row['name'] ?? ($row['title'] ?? '')),
				'order' => (int)($row['order'] ?? 0),
				'satisfied' => $verdict['satisfied'],
				'unmet' => $verdict['unmet'],
			];
		}

		usort(
			$verdicts,
			static fn (array $left, array $right): int => ($left['order'] <=> $right['order']),
		);

		return $verdicts;
	}//end verdictsFor()

	/**
	 * Whether this status is one the case type derives rather than offers.
	 *
	 * The transition list asks this per destination: a derived status is not a
	 * move somebody picks, so it is dropped from the list rather than offered
	 * and then fought over.
	 *
	 * @param string $caseTypeId   The case type.
	 * @param string $statusTypeId The status.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	public function isDerivedStatus(string $caseTypeId, string $statusTypeId): bool {
		if ($caseTypeId === '' || $statusTypeId === '') {
			return false;
		}

		foreach ($this->caseTypes->statusTypesFor(caseTypeId: $caseTypeId) as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$id = (string)($row['id'] ?? ($row['uuid'] ?? ''));
			if ($id !== $statusTypeId) {
				continue;
			}

			return $this->declaration->isDerived(statusType: $row);
		}

		return false;
	}//end isDerivedStatus()

	/**
	 * The status the case should be in, when a derivation now says so.
	 *
	 * The FURTHEST satisfied status wins, by `order`. A case that satisfies
	 * both "Received" and "Complete" is complete: the earlier one stayed true,
	 * it did not become true again, and moving the case backwards on the
	 * strength of a condition it met weeks ago would undo a handler's work.
	 *
	 * Returns null when nothing derives, when the case is already there, or
	 * when the case sits at a LATER status than the one that derives. The last
	 * of those is what stops a derivation dragging a case out of Decision back
	 * into Complete every time a document is touched.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return string|null The statusType id to move to, or null for no move.
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	public function statusFor(array $case): ?string {
		$currentId = (string)($case['status'] ?? '');
		$verdicts = $this->verdictsFor(case: $case);

		$target = null;
		foreach ($verdicts as $verdict) {
			if ($verdict['satisfied'] === true) {
				$target = $verdict;
			}
		}

		if ($target === null || $target['statusId'] === $currentId) {
			return null;
		}

		if ($this->orderOf(case: $case, statusId: $currentId) > $target['order']) {
			return null;
		}

		return $target['statusId'];
	}//end statusFor()

	/**
	 * What is missing for the earliest derived status that has not fired.
	 *
	 * One status rather than all of them, because a handler acts on one thing
	 * at a time and the earliest unmet one is the thing in their way.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array{statusId: string, name: string, unmet: array<int, string>}|null
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	public function blockingReasonFor(array $case): ?array {
		$currentOrder = $this->orderOf(case: $case, statusId: (string)($case['status'] ?? ''));

		foreach ($this->verdictsFor(case: $case) as $verdict) {
			if ($verdict['satisfied'] === true || $verdict['order'] < $currentOrder) {
				continue;
			}

			return [
				'statusId' => $verdict['statusId'],
				'name' => $verdict['name'],
				'unmet' => $verdict['unmet'],
			];
		}

		return null;
	}//end blockingReasonFor()

	/**
	 * A status's declared order within the case's type.
	 *
	 * @param array<string, mixed> $case     The case.
	 * @param string               $statusId The status.
	 *
	 * @return int The order, or 0 when the status does not resolve.
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	private function orderOf(array $case, string $statusId): int {
		if ($statusId === '') {
			return 0;
		}

		foreach ($this->caseTypes->statusTypesFor(caseTypeId: (string)($case['caseType'] ?? '')) as $row) {
			if (is_array($row) === false) {
				continue;
			}

			if ((string)($row['id'] ?? ($row['uuid'] ?? '')) === $statusId) {
				return (int)($row['order'] ?? 0);
			}
		}

		return 0;
	}//end orderOf()
}//end class
