<?php

/**
 * An inspection run as it starts: on which case, with which frozen template, from which request, by whom.
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
 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-panel-on-case-dashboard
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Inspection;

/**
 * An inspection run as it starts: on which case, with which frozen template, from which request, by whom.
 *
 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-panel-on-case-dashboard
 */
final class InspectionRunStart {

	/**
	 * Constructor.
	 *
	 * @param string               $caseId   The case uuid.
	 * @param array<string, mixed> $template The frozen template.
	 * @param array<string, mixed> $payload  The request body.
	 * @param string               $actor    The inspector.
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-panel-on-case-dashboard
	 */
	public function __construct(
		public readonly string $caseId,
		public readonly array $template,
		public readonly array $payload,
		public readonly string $actor,
	) {
	}//end __construct()
}//end class
