<?php

/**
 * One source run on its way to a task
 *
 * What InspectionRunCarryOver::taskData() carries between its steps: the
 * source schema and object, the template it was filled against, its answers
 * and the template snapshot.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Inspection;

/**
 * The state of one carried-over source run.
 *
 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-rapport-creation
 */
final class InspectionCarryOverRun {

	/**
	 * The source schema slug.
	 *
	 * @var string
	 */
	public string $schema = '';

	/**
	 * The source object.
	 *
	 * @var array<string, mixed>
	 */
	public array $object = [];

	/**
	 * The template it was filled against, when found.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $template = null;

	/**
	 * The answers, in the task's response form.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $answers = [];

	/**
	 * The template snapshot; [] when there is none.
	 *
	 * @var array<string, mixed>
	 */
	public array $snapshot = [];
}//end class
