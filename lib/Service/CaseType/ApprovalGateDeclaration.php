<?php

/**
 * Which acts of a case type wait for an approval decidiq walks.
 *
 * decidiq owns the walk. It holds the approvers, counts the signatures, applies
 * the threshold and decides when the route is done. dossiq owns one half of
 * that arrangement and only one: which of ITS OWN acts may not happen while the
 * walk is still running.
 *
 * 🔴 NOTHING HERE COMPUTES AN APPROVAL OUTCOME (D-1). A second answer to "have
 * enough of them signed" would be a second authority over a question with one
 * correct answer, and the two would disagree the first time somebody changed a
 * threshold. So this class reads a declaration and a stored reference, and
 * nothing else. The verdict is fetched from decidiq by
 * {@see \OCA\Dossiq\Service\Cases\ApprovalGate}.
 *
 * 🔑 THE REFERENCE LIVES ON THE CASE, THE RULE ON THE CASE TYPE. The case type
 * says "the besluit waits for an approval of type besluit-approval". The case
 * says "that approval is decidiq decision 7f3c...". Splitting them that way is
 * what lets a case type change its mind without rewriting every open case, and
 * what keeps dossiq from storing a single fact about the walk itself.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseType
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
 * @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseType;

/**
 * Reads the approval gates a case type declares, and the references a case carries.
 *
 * @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md
 */
final class ApprovalGateDeclaration {

	/**
	 * The case type property holding the gates.
	 *
	 * @var string
	 */
	public const DECLARATION = 'approvalGates';

	/**
	 * The case property holding the decidiq reference per gated act.
	 *
	 * @var string
	 */
	public const REFERENCES = 'approvalRefs';

	/**
	 * Private constructor: this is a reader over two arrays, not an object.
	 */
	private function __construct() {
	}//end __construct()

	/**
	 * The gates a case type declares, normalised and in declared order.
	 *
	 * A gate naming no act gates nothing, and an act nobody can invoke is not a
	 * rule, it is a typo that would refuse every act or none. So it is dropped
	 * here rather than half-applied later.
	 *
	 * A disabled gate is dropped too. It stays in the stored declaration, which
	 * is the point: lifting a gate should not lose the record that it was once
	 * there and what it said.
	 *
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return array<int, array{act: string, decisionType: string, label: string}> The active gates.
	 *
	 * @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md#requirement-a-case-is-gated-by-the-approval-outcome-decidiq-walks-req-dec-01
	 */
	public static function declaredOn(array $caseType): array {
		$gates = [];

		foreach (self::rowsOf(value: ($caseType[self::DECLARATION] ?? [])) as $row) {
			$act = trim((string)($row['act'] ?? ''));
			if ($act === '' || ($row['enabled'] ?? true) === false) {
				continue;
			}

			$decisionType = trim((string)($row['decisionType'] ?? ''));
			$label = trim((string)($row['label'] ?? ''));
			if ($label === '') {
				$label = $decisionType;
			}

			$gates[] = [
				'act' => $act,
				'decisionType' => $decisionType,
				'label' => $label,
			];
		}//end foreach

		return $gates;
	}//end declaredOn()

	/**
	 * The gate on one act, or an empty array when that act is not gated.
	 *
	 * The FIRST matching gate wins. Two gates on one act is a configuration
	 * mistake rather than a supported arrangement, and refusing on the first is
	 * the reading that keeps the refusal sentence single and nameable.
	 *
	 * @param array<string, mixed> $caseType The effective case type row.
	 * @param string               $act      The act being asked about.
	 *
	 * @return array{act: string, decisionType: string, label: string}|array{} The gate, or [].
	 *
	 * @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md#requirement-a-case-is-gated-by-the-approval-outcome-decidiq-walks-req-dec-01
	 */
	public static function gateFor(array $caseType, string $act): array {
		$act = trim($act);
		if ($act === '') {
			return [];
		}

		foreach (self::declaredOn(caseType: $caseType) as $gate) {
			if ($gate['act'] === $act) {
				return $gate;
			}
		}

		return [];
	}//end gateFor()

	/**
	 * The decidiq decision id this case's gated act waits on, or ''.
	 *
	 * 🔑 AN EMPTY REFERENCE IS NOT AN ABSENT GATE. It means the approval has
	 * not been raised yet, which is a reason to refuse the act, not a reason to
	 * allow it. The caller decides that; this method only reports what is
	 * stored.
	 *
	 * @param array<string, mixed> $case The stored case.
	 * @param string               $act  The gated act.
	 *
	 * @return string The decidiq decision id, or '' when none is recorded.
	 *
	 * @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md#requirement-a-case-is-gated-by-the-approval-outcome-decidiq-walks-req-dec-01
	 */
	public static function referenceFor(array $case, string $act): string {
		$act = trim($act);
		if ($act === '') {
			return '';
		}

		foreach (self::rowsOf(value: ($case[self::REFERENCES] ?? [])) as $row) {
			if (trim((string)($row['act'] ?? '')) === $act) {
				return trim((string)($row['decisionRef'] ?? ''));
			}
		}

		return '';
	}//end referenceFor()

	/**
	 * Record which decidiq decision an act waits on, replacing any earlier one.
	 *
	 * Returns the list to store rather than storing it: the writer of a case is
	 * one collaborator in this app and it is not this one.
	 *
	 * @param array<string, mixed> $case        The stored case.
	 * @param string               $act         The gated act.
	 * @param string               $decisionRef The decidiq decision id.
	 * @param string               $raisedAt    When it was raised, ISO 8601.
	 *
	 * @return array<int, array{act: string, decisionRef: string, raisedAt: string}> The new list.
	 *
	 * @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md#requirement-a-case-is-gated-by-the-approval-outcome-decidiq-walks-req-dec-01
	 */
	public static function withReference(array $case, string $act, string $decisionRef, string $raisedAt): array {
		$act = trim($act);
		$refs = [];

		foreach (self::rowsOf(value: ($case[self::REFERENCES] ?? [])) as $row) {
			$rowAct = trim((string)($row['act'] ?? ''));
			if ($rowAct === '' || $rowAct === $act) {
				continue;
			}

			$refs[] = [
				'act' => $rowAct,
				'decisionRef' => trim((string)($row['decisionRef'] ?? '')),
				'raisedAt' => trim((string)($row['raisedAt'] ?? '')),
			];
		}//end foreach

		if ($act !== '') {
			$refs[] = [
				'act' => $act,
				'decisionRef' => trim($decisionRef),
				'raisedAt' => trim($raisedAt),
			];
		}

		return $refs;
	}//end withReference()

	/**
	 * A stored list, whether it arrived as an array or as a JSON string.
	 *
	 * OpenRegister hands an array property back as an array, but a case type
	 * imported from an export, or written by a client that encoded it, arrives
	 * as a string. Reading only one of the two would make a declaration that is
	 * plainly there behave as if it were absent, with nothing saying so.
	 *
	 * @param mixed $value The stored property.
	 *
	 * @return array<int, array<string, mixed>> The rows, non-arrays dropped.
	 */
	private static function rowsOf(mixed $value): array {
		if (is_string($value) === true) {
			$decoded = json_decode($value, true);
			$value = [];
			if (is_array($decoded) === true) {
				$value = $decoded;
			}
		}

		if (is_array($value) === false) {
			return [];
		}

		$rows = [];
		foreach ($value as $row) {
			if (is_array($row) === true) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end rowsOf()
}//end class
