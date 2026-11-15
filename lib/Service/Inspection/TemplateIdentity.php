<?php

/**
 * What a migrated inspection template is called, which case type it belongs to, its status and the checklist it came from.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Inspection
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
 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-checklist-schema
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Inspection;

/**
 * What a migrated inspection template is called, which case type it belongs to, its status and the checklist it came from.
 *
 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-checklist-schema
 */
final class TemplateIdentity {

	/**
	 * Constructor.
	 *
	 * @param string $name      Its name.
	 * @param string $caseType  Its case type uuid, or empty.
	 * @param string $status    draft, active or retired.
	 * @param string $legacyRef The source reference.
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-checklist-schema
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $caseType,
		public readonly string $status,
		public readonly string $legacyRef,
	) {
	}//end __construct()
}//end class
