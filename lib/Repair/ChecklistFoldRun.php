<?php

/**
 * Dossiq Checklist Fold Run
 *
 * One run of the checklist template fold: where it reads and writes, what is
 * already folded, and what it counted so far.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-checklist-schema
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCP\Migration\IOutput;

/**
 * One checklist template fold. It never leaves FoldInspectionChecklistTemplates.
 *
 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-checklist-schema
 */
final class ChecklistFoldRun {
	/**
	 * Legacy references already on a template.
	 *
	 * @var array<string, bool>
	 */
	public array $folded = [];

	/**
	 * The running counts.
	 *
	 * @var array{folded: int, already: int, unreadable: int}
	 */
	public array $counts = ['folded' => 0, 'already' => 0, 'unreadable' => 0];

	/**
	 * Where the fold reports what it left.
	 *
	 * @var IOutput
	 */
	public IOutput $output;

	/**
	 * Constructor.
	 *
	 * @param object $objects  The ObjectService.
	 * @param string $register The register id.
	 */
	public function __construct(
		public readonly object $objects,
		public readonly string $register,
	) {
	}//end __construct()
}//end class
