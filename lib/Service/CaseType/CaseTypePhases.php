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
 * @spec openspec/specs/case-types/spec.md
 * @spec openspec/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseType;

use OCA\Dossiq\Kernel\Contracts\CaseTypePhasesInterface;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\Support\TranslatedText;
use OCA\Dossiq\Service\Task\TaskDeclaration;
use OCA\Dossiq\Service\WorkflowTemplateLoader;

/**
 * Groups a case type's workflow steps under its statuses.
 *
 * @spec openspec/specs/case-types/spec.md
 */
class CaseTypePhases implements CaseTypePhasesInterface {

	/**
	 * Constructor.
	 *
	 * @param CaseTypeResolver       $resolver     The effective statuses and case type.
	 * @param WorkflowTemplateLoader $templates    The active workflow definition, steps decoded.
	 * @param TaskDeclaration        $declarations How a step declares its task.
	 * @param TranslatedText         $text         Reads a translatable status name in the reader's language.
	 * @param CaseTypeStore          $store        Reads the role types of the case type and its ancestors.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function __construct(
		private readonly CaseTypeResolver $resolver,
		private readonly WorkflowTemplateLoader $templates,
		private readonly TaskDeclaration $declarations,
		private readonly TranslatedText $text,
		private readonly CaseTypeStore $store,
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
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function phasesFor(string $caseTypeId): ?array {
		$caseType = $this->resolver->effectiveCaseType(caseTypeId: $caseTypeId);
		if ($caseType === []) {
			return null;
		}

		$grouped  = $this->phasesOf(caseTypeId: $caseTypeId);
		[$phases, $unplaced, $count] = $this->placedTasks(caseTypeId: $caseTypeId, grouped: $grouped);

		return [
			'caseTypeId'      => $caseTypeId,
			'phases'          => $this->ordered(phases: $this->withoutBareFinals(phases: $phases, phased: $grouped['phased'])),
			'unplaced'        => $unplaced,
			'alwaysAvailable' => $this->alwaysAvailable(caseType: $caseType),
			'taskCount'       => $count,
		];
	}//end phasesFor()

	/**
	 * The phases with each running task placed in its status's phase, the tasks no phase took, and the count.
	 *
	 * @param string               $caseTypeId The case type id.
	 * @param array<string, mixed> $grouped    The empty phases and the phase key per status, from {@see phasesOf()}.
	 *
	 * @return array{0: array<string, array<string, mixed>>, 1: array<int, array<string, mixed>>, 2: int}
	 *         The phases, the unplaced tasks and the task count.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	private function placedTasks(string $caseTypeId, array $grouped): array {
		$phases   = $grouped['phases'];
		$roles    = $this->roleNames(caseTypeId: $caseTypeId);
		$unplaced = [];
		$count    = 0;
		foreach ($this->stepsOf(caseTypeId: $caseTypeId) as $step) {
			$task = $this->taskOf(step: $step, roles: $roles);
			if ($task === null) {
				continue;
			}

			$count++;
			$status = $this->declarations->statusOf(step: $step);
			$key    = ($grouped['phaseOfStatus'][$status] ?? null);
			if ($key !== null && isset($phases[$key]) === true) {
				$phases[$key]['tasks'][] = $task;
				continue;
			}

			$unplaced[] = $task;
		}

		return [$phases, $unplaced, $count];
	}//end placedTasks()

	/**
	 * The case type's phases, empty, and which phase each status falls under.
	 *
	 * A status names its phase with the optional `phase`; the statuses that
	 * name one phase share it, named by that phase and ordered by the first
	 * of them. A status without one is a phase of its own, named after it.
	 *
	 * @param string $caseTypeId The case type id.
	 *
	 * @return array{phases: array<string, array<string, mixed>>, phaseOfStatus: array<string, string>, phased: bool}
	 *         The phases keyed by phase key, the phase key per status id, and whether any status names a phase.
	 */
	private function phasesOf(string $caseTypeId): array {
		$phases  = [];
		$ofStatus = [];
		$phased  = false;
		foreach ($this->resolver->statusTypesFor(caseTypeId: $caseTypeId) as $statusType) {
			$id = (string) ($statusType['id'] ?? ($statusType['@self']['id'] ?? ''));
			if ($id === '') {
				continue;
			}

			$placed = $this->placementOf(statusType: $statusType, id: $id);
			$key    = $placed['key'];
			if ($placed['phase'] !== '') {
				$phased = true;
			}

			$ofStatus[$id] = $key;
			if (isset($phases[$key]) === true) {
				$phases[$key] = $this->joinedPhase(phase: $phases[$key], id: $id, order: $placed['order']);
				continue;
			}

			$phases[$key] = $this->newPhase(caseTypeId: $caseTypeId, statusType: $statusType, id: $id, placed: $placed);
		}//end foreach

		return ['phases' => $phases, 'phaseOfStatus' => $ofStatus, 'phased' => $phased];
	}//end phasesOf()

	/**
	 * A phase opened by its first status, without tasks yet.
	 *
	 * @param string               $caseTypeId The case type id.
	 * @param array<string, mixed> $statusType The status type.
	 * @param string               $id         The status type id.
	 * @param array<string, mixed> $placed     Where the status falls, from {@see placementOf()}.
	 *
	 * @return array<string, mixed> The phase.
	 */
	private function newPhase(string $caseTypeId, array $statusType, string $id, array $placed): array {
		return [
			'id'         => $id,
			'caseTypeId' => $caseTypeId,
			'name'       => $placed['name'],
			'order'      => $placed['order'],
			'isFinal'    => (($statusType['isFinal'] ?? false) === true && $placed['phase'] === ''),
			'statusIds'  => [$id],
			'tasks'      => [],
		];
	}//end newPhase()

	/**
	 * Where a status falls: the phase it names, or a phase of its own named after it.
	 *
	 * @param array<string, mixed> $statusType The status type.
	 * @param string               $id         The status type id.
	 *
	 * @return array{phase: string, order: int, key: string, name: string} The named phase ('' when none),
	 *         the status's order, and the phase key and name it falls under.
	 */
	private function placementOf(array $statusType, string $id): array {
		$phase = trim($this->text->forReader(value: ($statusType['phase'] ?? '')));
		$order = (int) ($statusType['order'] ?? 0);
		$key   = 'status:'.$id;
		$name  = $this->text->forReader(value: ($statusType['name'] ?? ''));
		if ($phase !== '') {
			$key  = 'phase:'.mb_strtolower($phase);
			$name = $phase;
		}

		return ['phase' => $phase, 'order' => $order, 'key' => $key, 'name' => $name];
	}//end placementOf()

	/**
	 * A phase with one more status, named by that status when it comes first.
	 *
	 * @param array<string, mixed> $phase The phase.
	 * @param string               $id    The status type id.
	 * @param int                  $order The status's order.
	 *
	 * @return array<string, mixed> The phase.
	 */
	private function joinedPhase(array $phase, string $id, int $order): array {
		$phase['statusIds'][] = $id;
		if ($order < $phase['order']) {
			$phase['order'] = $order;
			$phase['id']    = $id;
		}

		return $phase;
	}//end joinedPhase()

	/**
	 * The phases without a final status of its own that runs no task, once
	 * the statuses are grouped in phases: the end of a case is not a phase
	 * the case type's work happens in.
	 *
	 * @param array<string, array<string, mixed>> $phases The phases.
	 * @param bool                                $phased Whether any status names a phase.
	 *
	 * @return array<string, array<string, mixed>> The phases to show.
	 */
	private function withoutBareFinals(array $phases, bool $phased): array {
		if ($phased === false) {
			return $phases;
		}

		return array_filter($phases, static fn (array $phase): bool => ($phase['isFinal'] === false || $phase['tasks'] !== []));
	}//end withoutBareFinals()

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
			// The phase's place in the case type, counted from 1: "Fase 2: Beoordeling", whatever its first status's order.
			$phase['number'] = ($index + 1);
			$phases[$index]  = $phase;
		}

		return $phases;
	}//end ordered()

	/**
	 * The acts available in every phase, as the case type declares them.
	 *
	 * @param array<string, mixed> $caseType The effective case type.
	 *
	 * @return array<int, array<string, mixed>> The acts.
	 */
	private function alwaysAvailable(array $caseType): array {
		return (new AlwaysAvailableActDeclaration())->actsOn(caseType: $caseType);
	}//end alwaysAvailable()

	/**
	 * The name of every role the case type or one of its ancestors declares,
	 * by role id, in the reader's language.
	 *
	 * @param string $caseTypeId The case type id.
	 *
	 * @return array<string, string> The names by role type id.
	 */
	private function roleNames(string $caseTypeId): array {
		$names = [];
		foreach ($this->resolver->chainFor(caseTypeId: $caseTypeId) as $caseType) {
			$rows = $this->store->rowsOfType(schemaKey: 'role_type_schema', caseTypeId: $this->store->rowId(row: $caseType));
			foreach ($rows as $roleType) {
				$id = $this->store->rowId(row: $roleType);
				if ($id !== '' && isset($names[$id]) === false) {
					$names[$id] = trim($this->text->forReader(value: ($roleType['name'] ?? '')));
				}
			}
		}

		return $names;
	}//end roleNames()

	/**
	 * One step as the task card a phase lists, or null when it runs no task.
	 *
	 * A task names the role it routes to (`roleName`, empty when it routes to
	 * none the type has), so a card reads "Intaker" rather than a group id.
	 *
	 * @param array<string, mixed>  $step  The decoded workflow step.
	 * @param array<string, string> $roles The role names by role type id.
	 *
	 * @return array<string, mixed>|null The task, or null.
	 */
	private function taskOf(array $step, array $roles): ?array {
		$declaration = $this->declarations->forStep(step: $step);
		if ($declaration['enabled'] === false) {
			return null;
		}

		$routing = ($step['routingRule'] ?? []);
		if (is_array($routing) === false) {
			$routing = [];
		}

		$roleTypeId = (string) ($routing['roleType'] ?? ($step['assigneeRole'] ?? ''));

		return [
			'id'              => (string) ($step['id'] ?? ''),
			'title'           => $this->declarations->titleOf(step: $step),
			'description'     => trim((string) ($step['description'] ?? '')),
			'order'           => (int) ($step['order'] ?? 0),
			'required'        => (($step['isRequired'] ?? false) === true),
			'roleTypeId'      => $roleTypeId,
			'roleName'        => ($roles[$roleTypeId] ?? ''),
			'candidateGroups' => $declaration['candidateGroups'],
			'leadTimeDays'    => $declaration['leadTimeDays'],
		];
	}//end taskOf()
}//end class
