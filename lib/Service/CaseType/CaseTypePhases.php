<?php

/**
 * Dossiq case type phases.
 *
 * The phases of a case type with the tasks each one runs, and the acts that are
 * available in every phase. A page cannot assemble this itself: the tasks live
 * in the active workflow definition as `steps`, a JSON-encoded string a
 * declarative widget cannot read or group, and the phases of a child type come
 * from its parent. This reads both through the services that already own them
 * and answers one plain structure.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseType
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
 * @spec openspec/changes/screens-round12-case-type-phases/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseType;

use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\Task\TaskDeclaration;
use OCA\Dossiq\Service\WorkflowTemplateLoader;

/**
 * Groups a case type's workflow steps under its statuses.
 *
 * @spec openspec/changes/screens-round12-case-type-phases/specs/case-types/spec.md
 */
class CaseTypePhases {

	/**
	 * Constructor.
	 *
	 * @param CaseTypeResolver       $resolver     The effective statuses and case type.
	 * @param WorkflowTemplateLoader $templates    The active workflow definition, steps decoded.
	 * @param TaskDeclaration        $declarations How a step declares its task.
	 *
	 * @spec openspec/changes/screens-round12-case-type-phases/specs/case-types/spec.md
	 */
	public function __construct(
		private readonly CaseTypeResolver $resolver,
		private readonly WorkflowTemplateLoader $templates,
		private readonly TaskDeclaration $declarations,
	) {
	}//end __construct()

	/**
	 * The phases of a case type, each with its tasks in order, and the acts
	 * available in every phase.
	 *
	 * A step whose task is switched off is left out: the case type does not run
	 * it. A step naming a status the type does not have is not dropped silently
	 * but listed under `unplaced`, so an author sees it.
	 *
	 * @param string $caseTypeId The case type id.
	 *
	 * @return array{caseTypeId: string, phases: array<int, array<string, mixed>>,
	 *               unplaced: array<int, array<string, mixed>>,
	 *               alwaysAvailable: array<int, array<string, mixed>>, taskCount: int}|null
	 *         The phases, or null when the case type cannot be read.
	 *
	 * @spec openspec/changes/screens-round12-case-type-phases/specs/case-types/spec.md
	 */
	public function phasesFor(string $caseTypeId): ?array {
		$caseType = $this->resolver->effectiveCaseType(caseTypeId: $caseTypeId);
		if ($caseType === []) {
			return null;
		}

		$phases   = $this->phasesOf(caseTypeId: $caseTypeId);
		$unplaced = [];
		$count    = 0;
		foreach ($this->stepsOf(caseTypeId: $caseTypeId) as $step) {
			$task = $this->taskOf(step: $step);
			if ($task === null) {
				continue;
			}

			$count++;
			$status = $this->declarations->statusOf(step: $step);
			if (isset($phases[$status]) === true) {
				$phases[$status]['tasks'][] = $task;
				continue;
			}

			$unplaced[] = $task;
		}

		return [
			'caseTypeId'      => $caseTypeId,
			'phases'          => $this->ordered(phases: $phases),
			'unplaced'        => $unplaced,
			'alwaysAvailable' => $this->alwaysAvailable(caseType: $caseType),
			'taskCount'       => $count,
		];
	}//end phasesFor()

	/**
	 * The case type's statuses as empty phases, keyed by status id.
	 *
	 * @param string $caseTypeId The case type id.
	 *
	 * @return array<string, array<string, mixed>> The phases.
	 */
	private function phasesOf(string $caseTypeId): array {
		$phases = [];
		foreach ($this->resolver->statusTypesFor(caseTypeId: $caseTypeId) as $statusType) {
			$id = (string) ($statusType['id'] ?? ($statusType['@self']['id'] ?? ''));
			if ($id === '') {
				continue;
			}

			$phases[$id] = [
				'id'      => $id,
				'name'    => ($statusType['name'] ?? ''),
				'order'   => (int) ($statusType['order'] ?? 0),
				'isFinal' => (($statusType['isFinal'] ?? false) === true),
				'tasks'   => [],
			];
		}

		return $phases;
	}//end phasesOf()

	/**
	 * The decoded steps of the case type's active workflow definition.
	 *
	 * @param string $caseTypeId The case type id.
	 *
	 * @return array<int, array<string, mixed>> The steps that are objects.
	 */
	private function stepsOf(string $caseTypeId): array {
		$template = $this->templates->getActiveTemplate(caseTypeId: $caseTypeId);
		$steps    = ($template['steps'] ?? []);
		if (is_array($steps) === false) {
			return [];
		}

		return array_values(array_filter($steps, 'is_array'));
	}//end stepsOf()

	/**
	 * The phases in status order, each with its tasks in step order.
	 *
	 * @param array<string, array<string, mixed>> $phases The phases, keyed by id.
	 *
	 * @return array<int, array<string, mixed>> The ordered phases.
	 */
	private function ordered(array $phases): array {
		$phases = array_values($phases);
		usort($phases, static fn (array $left, array $right): int => ($left['order'] <=> $right['order']));
		foreach ($phases as $index => $phase) {
			usort($phase['tasks'], static fn (array $left, array $right): int => ($left['order'] <=> $right['order']));
			$phases[$index] = $phase;
		}

		return $phases;
	}//end ordered()

	/**
	 * The acts available in every phase, as the case type declares them.
	 *
	 * @param array<string, mixed> $caseType The effective case type.
	 *
	 * @return array<int, array<string, mixed>> The acts.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) declaredOn() is the one normaliser of the declaration, a pure function.
	 */
	private function alwaysAvailable(array $caseType): array {
		return AlwaysAvailableActs::declaredOn(caseType: $caseType);
	}//end alwaysAvailable()

	/**
	 * One step as the task card a phase lists, or null when it runs no task.
	 *
	 * @param array<string, mixed> $step The decoded workflow step.
	 *
	 * @return array<string, mixed>|null The task, or null.
	 */
	private function taskOf(array $step): ?array {
		$declaration = $this->declarations->forStep(step: $step);
		if ($declaration['enabled'] === false) {
			return null;
		}

		$routing = ($step['routingRule'] ?? []);
		if (is_array($routing) === false) {
			$routing = [];
		}

		return [
			'id'              => (string) ($step['id'] ?? ''),
			'title'           => $this->declarations->titleOf(step: $step),
			'description'     => trim((string) ($step['description'] ?? '')),
			'order'           => (int) ($step['order'] ?? 0),
			'required'        => (($step['isRequired'] ?? false) === true),
			'roleTypeId'      => (string) ($routing['roleType'] ?? ($step['assigneeRole'] ?? '')),
			'candidateGroups' => $declaration['candidateGroups'],
			'leadTimeDays'    => $declaration['leadTimeDays'],
		];
	}//end taskOf()
}//end class
