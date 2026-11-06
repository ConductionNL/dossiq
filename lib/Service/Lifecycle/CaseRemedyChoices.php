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
 * @spec openspec/changes/dialogs-answer-what-the-boards-draw/specs/remedy-lodging/spec.md#requirement-the-lifecycle-menu-offers-the-remedies-the-case-type-declares
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Lifecycle;

use OCA\Dossiq\Service\Beschikking\CaseRemedy;
use OCP\IL10N;

/**
 * Lists the remedies of a case for its lifecycle menu.
 *
 * @spec openspec/changes/dialogs-answer-what-the-boards-draw/specs/remedy-lodging/spec.md#requirement-the-lifecycle-menu-offers-the-remedies-the-case-type-declares
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
	 * @spec openspec/changes/dialogs-answer-what-the-boards-draw/specs/remedy-lodging/spec.md#requirement-the-lifecycle-menu-offers-the-remedies-the-case-type-declares
	 */
	public function forCase(string $caseId, array $case): array {
		$declared = $this->remedy->declarationOf(caseId: $caseId);
		if ($declared['enabled'] === false || $declared['caseType'] === '') {
			return [];
		}

		$id = $declared['id'];
		if ($id === '') {
			$id = $declared['kind'];
		}

		$label = $declared['label'];
		if ($label === '') {
			$label = $this->l10n->t('Register a remedy');
		}

		$available = trim((string)($case[CaseRemedy::SENT_FIELD] ?? '')) !== '';
		$reason    = null;
		if ($available === false) {
			$reason = $this->l10n->t('Only after a decision has been made known.');
		}

		return [
			[
				'id' => $id,
				'label' => $label,
				'description' => $declared['description'],
				'available' => $available,
				'reason' => $reason,
			],
		];
	}//end forCase()
}//end class
