<?php

/**
 * Dossiq StatusEntryGuards.
 *
 * The guard evaluators that read the status being entered: its capacity, the walked approval, the assessed documents and the required documents.
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
 * The guard evaluators that read the status being entered: its capacity, the walked approval, the assessed documents and the required documents.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
final class StatusEntryGuards {
	/**
	 * Constructor.
	 *
	 * @param CapacityGuard                     $capacity           Capacity-of-the-status-being-entered evaluator.
	 * @param ApprovalGuard|null                $approvalGuard      The walked-approval evaluator; null leaves its type unregistered.
	 * @param DocumentAssessmentGuard|null      $documentAssessment Whether the documents of a case are gathered and assessed; null: unregistered.
	 * @param StatusEntryRequirementsGuard|null $entryRequirements  The documents the status being entered requires; null leaves its type unregistered.
	 */
	public function __construct(
		public readonly CapacityGuard $capacity,
		public readonly ?ApprovalGuard $approvalGuard = null,
		public readonly ?DocumentAssessmentGuard $documentAssessment = null,
		public readonly ?StatusEntryRequirementsGuard $entryRequirements = null,
	) {
	}//end __construct()
}//end class
