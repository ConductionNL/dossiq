<?php

/**
 * Dossiq CaseRemedyChoices.
 *
 * The remedies a case's type declares, as entries for the case's lifecycle
 * menu: `{ id, label, description, available, reason }`. A remedy is
 * available once a decision of the case has been made known (the case's
 * `decisionSentOn`); before that it carries the reason.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Lifecycle
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
 * @spec openspec/specs/remedy-lodging/spec.md#requirement-the-lifecycle-menu-offers-the-remedies-the-case-type-declares
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Lifecycle;

use OCA\Dossiq\Service\Beschikking\CaseRemedy;
use OCP\IL10N;

/**
 * Lists the remedies of a case for its lifecycle menu.
 *
 * @spec openspec/specs/remedy-lodging/spec.md#requirement-the-lifecycle-menu-offers-the-remedies-the-case-type-declares
 */
class CaseRemedyChoices {

	/**
	 * Constructor.
	 *
	 * @param CaseRemedy $remedy Reads the remedy the case type declares.
	 * @param IL10N      $l10n   Translates the fallback label and the reason.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CaseRemedy $remedy,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The remedy entries of one case.
	 *
	 * @param string               $caseId The case uuid.
	 * @param array<string, mixed> $case   The case, already loaded.
	 *
	 * @return array<int, array{id: string, label: string, description: string, available: bool, reason: string|null}>
	 *
	 * @spec openspec/specs/remedy-lodging/spec.md#requirement-the-lifecycle-menu-offers-the-remedies-the-case-type-declares
	 */
	public function forCase(string $caseId, array $case): array {
		$declared = $this->remedy->declarationOf(caseId: $caseId);
		if ($declared['enabled'] === false || $declared['caseType'] === '') {
			return [];
		}

		return [$this->entryOf(declared: $declared, caseRow: $case)];
	}//end forCase()

	/**
	 * The menu entry of a declared remedy.
	 *
	 * @param array{id: string, kind: string, label: string, description: string} $declared The case type's remedy declaration.
	 * @param array<string, mixed> $caseRow  The case, already loaded.
	 *
	 * @return array{id: string, label: string, description: string, available: bool, reason: string|null}
	 *
	 * @spec openspec/specs/remedy-lodging/spec.md#requirement-the-lifecycle-menu-offers-the-remedies-the-case-type-declares
	 */
	private function entryOf(array $declared, array $caseRow): array {
		$id = $this->idOf(declared: $declared);
		$label = $this->labelOf(declared: $declared);

		$available = trim((string)($caseRow[CaseRemedy::SENT_FIELD] ?? '')) !== '';
		$reason    = $this->reasonFor(available: $available);

		return [
			'id' => $id,
			'label' => $label,
			'description' => $declared['description'],
			'available' => $available,
			'reason' => $reason,
		];
	}//end entryOf()

	/**
	 * The entry's id: the declared one, else the remedy's kind.
	 *
	 * @param array{id: string, kind: string} $declared The case type's remedy declaration.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/specs/remedy-lodging/spec.md#requirement-the-lifecycle-menu-offers-the-remedies-the-case-type-declares
	 */
	private function idOf(array $declared): string {
		$id = $declared['id'];
		if ($id === '') {
			$id = $declared['kind'];
		}

		return $id;
	}//end idOf()

	/**
	 * The entry's label: the declared one, else the default.
	 *
	 * @param array{label: string} $declared The case type's remedy declaration.
	 *
	 * @return string The label.
	 *
	 * @spec openspec/specs/remedy-lodging/spec.md#requirement-the-lifecycle-menu-offers-the-remedies-the-case-type-declares
	 */
	private function labelOf(array $declared): string {
		$label = $declared['label'];
		if ($label === '') {
			$label = $this->l10n->t('Register a remedy');
		}

		return $label;
	}//end labelOf()

	/**
	 * Why the remedy cannot be lodged yet, or null when it can.
	 *
	 * @param bool $available Whether the decision has been made known.
	 *
	 * @return string|null The reason.
	 *
	 * @spec openspec/specs/remedy-lodging/spec.md#requirement-the-lifecycle-menu-offers-the-remedies-the-case-type-declares
	 */
	private function reasonFor(bool $available): ?string {
		$reason = null;
		if ($available === false) {
			$reason = $this->l10n->t('Only after a decision has been made known.');
		}

		return $reason;
	}//end reasonFor()
}//end class
