<?php

/**
 * The terms of a case read by their kind.
 *
 * The shaped terms of one case, as `CaseTermsService::termsForCase()` lists
 * them: the first of a kind, and whether any phase, planned or statutory term
 * is past its end. Nothing here reads a store or a date.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Term
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
 * @spec openspec/specs/termijn-reporting/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Term;

use OCA\Dossiq\Service\TermKind;

/**
 * Reads a case's shaped terms by kind.
 *
 * @spec openspec/specs/termijn-reporting/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class TermsByKind {

	/**
	 * The first term of a kind, or null.
	 *
	 * @param array<int, array<string, mixed>> $terms The shaped terms.
	 * @param string $kind The kind to look for.
	 *
	 * @return array<string, mixed>|null The term.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function first(array $terms, string $kind): ?array {
		foreach ($terms as $term) {
			if (($term['kind'] ?? '') === $kind) {
				return $term;
			}
		}//end foreach

		return null;
	}//end first()


	/**
	 * Whether any phase, planned or statutory term is overdue.
	 *
	 * @param array<int, array<string, mixed>> $terms The case's terms.
	 *
	 * @return array{phaseOverdue: bool, plannedOverdue: bool, statutoryOverdue: bool}
	 *
	 * @spec openspec/specs/termijn-reporting/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function overdueFlags(array $terms): array {
		return [
			'phaseOverdue' => $this->anyOverdue(terms: $terms, kind: TermKind::PHASE),
			'plannedOverdue' => $this->anyOverdue(terms: $terms, kind: TermKind::PLANNED),
			'statutoryOverdue' => $this->anyOverdue(terms: $terms, kind: TermKind::STATUTORY),
		];
	}//end overdueFlags()


	/**
	 * Whether any running clock of a kind is past its end.
	 *
	 * @param array<int, array<string, mixed>> $terms The shaped terms.
	 * @param string $kind The kind to look at.
	 *
	 * @return bool True when at least one is overdue.
	 */
	private function anyOverdue(array $terms, string $kind): bool {
		foreach ($terms as $term) {
			if (($term['kind'] ?? '') === $kind && ($term['overdue'] ?? false) === true) {
				return true;
			}
		}//end foreach

		return false;
	}//end anyOverdue()
}//end class
