<?php

/**
 * Dossiq CaseContentGuards.
 *
 * The guard evaluators that read what the case holds: its checklist, fields, documents and the items of its current status.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transitions
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

namespace OCA\Dossiq\Service\Transitions;

/**
 * The guard evaluators that read what the case holds: its checklist, fields, documents and the items of its current status.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
final class CaseContentGuards {
	/**
	 * Constructor.
	 *
	 * @param ChecklistGuard        $checklist        Built-in checklist evaluator.
	 * @param RequiredFieldGuard    $requiredField    Built-in required-field evaluator.
	 * @param RequiredDocumentGuard $requiredDocument Built-in required-document evaluator.
	 * @param StatusChecklistGuard  $statusChecklist  Required-items-of-the-current-status evaluator.
	 * @param DateReachedGuard|null $dateReached      Date-reached evaluator (a closing date, a standstill term).
	 */
	public function __construct(
		public readonly ChecklistGuard $checklist,
		public readonly RequiredFieldGuard $requiredField,
		public readonly RequiredDocumentGuard $requiredDocument,
		public readonly StatusChecklistGuard $statusChecklist,
		public readonly ?DateReachedGuard $dateReached = null,
	) {
	}//end __construct()
}//end class
