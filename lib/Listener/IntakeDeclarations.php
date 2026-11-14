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

use OCA\Dossiq\Kernel\Contracts\AssigneeNarrowingInterface;
use OCA\Dossiq\Kernel\Contracts\CaseClassificationInterface;
use OCA\Dossiq\Kernel\Contracts\DuplicatePolicyInterface;
use OCA\Dossiq\Kernel\Contracts\IntakeRequirementsInterface;
use OCA\Dossiq\Service\Intake\SpecialCategoryClassification;

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
	 * @param IntakeRequirementsInterface $requirements   What must be answered, and when.
	 * @param CaseClassificationInterface $classification The facets and the access rule.
	 * @param AssigneeNarrowingInterface  $narrowing      Who may hold the case.
	 * @param DuplicatePolicyInterface    $duplicates     What this case type does about a case that already exists.
	 * @param SpecialCategoryClassification $specialCategory Whether the case type requires its special categories classified (REQ-SDC-SAFE-01).
	 */
	public function __construct(
		public readonly IntakeRequirementsInterface $requirements,
		public readonly CaseClassificationInterface $classification,
		public readonly AssigneeNarrowingInterface $narrowing,
		public readonly DuplicatePolicyInterface $duplicates,
		public readonly SpecialCategoryClassification $specialCategory = new SpecialCategoryClassification(),
	) {
	}//end __construct()
}//end class
