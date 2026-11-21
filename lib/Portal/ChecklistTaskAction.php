<?php

/**
 * Dossiq Checklist Task Action (inspection-checklists-onto-task 4.3)
 *
 * The inspector's portal action that completes the inspection task they are
 * assigned.
 *
 * @category Portal
 * @package  OCA\Dossiq\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inspection-checklists-onto-task/design.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Portal;

use OCA\Dossiq\Kernel\Contracts\ExternalInspectionRunInterface;

/**
 * The inspector completes the inspection task they are assigned.
 *
 * An endpoint action, not a flat write. The run is an OpenRegister task whose
 * `metadata.completeVia` names this action, so OpenRegister refuses to
 * complete it any other way and portaliq's task list opens this form with the
 * task uuid preset under `taskField`. dossiq takes the inspector from the
 * verified assertion, compares it with the task's stored party reference and
 * checks the answers against the frozen template before the task is completed
 * ({@see \OCA\Dossiq\Service\Inspection\ExternalInspectionRunService}). No
 * `type`, `register` or `schema`, so the flat writer never takes it for one
 * of its own.
 *
 * @spec openspec/changes/inspection-checklists-onto-task/design.md
 */
final class ChecklistTaskAction {

	/**
	 * The action, as the inspector contribution lists it.
	 *
	 * @var array<string, mixed>
	 */
	public const DEFINITION = [
		'id' => ExternalInspectionRunInterface::ACTION,
		'label' => 'Checklist invullen',
		'endpoint' => '/index.php/apps/dossiq/api/portal/inspectie/checklist',
		'method' => 'POST',
		'taskField' => 'taskId',
		'minTrust' => 'low',
		'fields' => ['responses', 'remarks'],
		'requiredFields' => ['responses'],
		'fieldConfigs' => [
			'responses' => ['label' => 'Antwoorden'],
			'remarks' => ['label' => 'Opmerkingen', 'size' => 'large', 'maxLength' => 4000],
		],
		'submitLabel' => 'Checklist indienen',
		'successMessage' => 'Wij hebben uw checklist ontvangen.',
	];
}//end class
