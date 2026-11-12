<?php

/**
 * What a case type declares about intake: its requirements, classification, assignee narrowing and duplicate policy.
 *
 * @category Service
 * @package  OCA\Dossiq\Listener
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
 * @spec openspec/specs/semantic-case-intake/spec.md
 * @spec openspec/specs/friendly-case-create-form/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\Intake\AssigneeNarrowing;
use OCA\Dossiq\Service\Intake\CaseClassification;
use OCA\Dossiq\Service\Intake\DuplicatePolicy;
use OCA\Dossiq\Service\Intake\IntakeRequirements;

/**
 * What a case type declares about intake: its requirements, classification, assignee narrowing and duplicate policy.
 *
 * @spec openspec/specs/semantic-case-intake/spec.md
 * @spec openspec/specs/friendly-case-create-form/spec.md
 */
final class IntakeDeclarations {
	/**
	 * Constructor.
	 *
	 * @param IntakeRequirements $requirements   What must be answered, and when.
	 * @param CaseClassification $classification The facets and the access rule.
	 * @param AssigneeNarrowing  $narrowing      Who may hold the case.
	 * @param DuplicatePolicy    $duplicates     What this case type does about a case that already exists.
	 */
	public function __construct(
		public readonly IntakeRequirements $requirements,
		public readonly CaseClassification $classification,
		public readonly AssigneeNarrowing $narrowing,
		public readonly DuplicatePolicy $duplicates,
	) {
	}//end __construct()
}//end class
