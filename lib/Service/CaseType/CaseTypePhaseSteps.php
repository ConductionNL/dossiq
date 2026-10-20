<?php

/**
 * Dossiq case type phase steps.
 *
 * Adds a task to a phase of a case type. A task is a step of the case type's
 * workflow definition, under the status it runs in, and steps live in the
 * definition's `steps` as a JSON-encoded string. A published definition does not
 * change: cases running on it keep the version they started on. So a task is
 * added to the case type's newest DRAFT definition, and runs once that draft is
 * published. A case type without a draft is refused; its author starts a new
 * version first.
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
 * @spec openspec/changes/case-type-phase-add-task/specs/case-type-phase-add-task/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseType;

use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\Workflow\WorkflowDefinitionRepository;
use OCA\Dossiq\Service\Workflow\WorkflowLifecycleGuard;
use OCP\Security\ISecureRandom;

/**
 * Adds a task to a phase of a case type's draft workflow definition.
 *
 * @spec openspec/changes/case-type-phase-add-task/specs/case-type-phase-add-task/spec.md
 */
class CaseTypePhaseSteps {

	/**
	 * The outcome: the task was added.
	 *
	 * @var string
	 */
	public const ADDED = 'added';

	/**
	 * The outcome: the request names no title.
	 *
	 * @var string
	 */
	public const NO_TITLE = 'no-title';

	/**
	 * The outcome: the phase is not a status of the case type.
	 *
	 * @var string
	 */
	public const NO_PHASE = 'no-phase';

	/**
	 * The outcome: the case type has no draft workflow definition.
	 *
	 * @var string
	 */
	public const NO_DRAFT = 'no-draft';

	/**
	 * The outcome: the draft could not be saved.
	 *
	 * @var string
	 */
	public const NOT_SAVED = 'not-saved';

	/**
	 * Constructor.
	 *
	 * @param CaseTypeResolver             $resolver    The case type's effective statuses.
	 * @param WorkflowDefinitionRepository $definitions The workflow definitions of a case type.
	 * @param WorkflowLifecycleGuard       $guard       Reads a definition's lifecycle status.
	 * @param ISecureRandom                $random      Makes the step id.
	 *
	 * @spec openspec/changes/case-type-phase-add-task/specs/case-type-phase-add-task/spec.md
	 */
	public function __construct(
		private readonly CaseTypeResolver $resolver,
		private readonly WorkflowDefinitionRepository $definitions,
		private readonly WorkflowLifecycleGuard $guard,
		private readonly ISecureRandom $random,
	) {
	}//end __construct()

	/**
	 * Add a task to a phase.
	 *
	 * @param string               $caseTypeId The case type id.
	 * @param string               $phaseId    The status the task runs in.
	 * @param array<string, mixed> $input      `title`, and optionally `description`, `leadTimeDays` and `candidateGroups`.
	 *
	 * @return array{outcome: string, step?: array<string, mixed>, definitionId?: string, version?: int}
	 *         The outcome, and on success the new step and the draft it was added to.
	 *
	 * @spec openspec/changes/case-type-phase-add-task/specs/case-type-phase-add-task/spec.md
	 */
	public function addStep(string $caseTypeId, string $phaseId, array $input): array {
		$title = trim((string) ($input['title'] ?? ''));
		if ($title === '') {
			return ['outcome' => self::NO_TITLE];
		}

		if ($this->isPhaseOf(caseTypeId: $caseTypeId, phaseId: $phaseId) === false) {
			return ['outcome' => self::NO_PHASE];
		}

		$draft = $this->newestDraft(caseTypeId: $caseTypeId);
		if ($draft === null) {
			return ['outcome' => self::NO_DRAFT];
		}

		$steps = $this->stepsOf(definition: $draft);
		$step  = [
			'id'          => $this->uuid(),
			'title'       => $title,
			'description' => trim((string) ($input['description'] ?? '')),
			'status'      => $phaseId,
			'order'       => ($this->lastOrderIn(steps: $steps, phaseId: $phaseId) + 1),
			'task'        => [
				'leadTimeDays'    => max(0, (int) ($input['leadTimeDays'] ?? 0)),
				'candidateGroups' => $this->names(value: ($input['candidateGroups'] ?? [])),
			],
		];
		$steps[] = $step;

		$encoded = $steps;
		if (is_string($draft['steps'] ?? null) === true) {
			$encoded = json_encode($steps);
		}

		$definitionId = (string) ($draft['id'] ?? ($draft['@self']['id'] ?? ''));
		$saved        = $this->definitions->save(payload: ['steps' => $encoded], uuid: $definitionId);
		if ($saved === null) {
			return ['outcome' => self::NOT_SAVED];
		}

		return [
			'outcome'      => self::ADDED,
			'step'         => $step,
			'definitionId' => $definitionId,
			'version'      => (int) ($draft['version'] ?? 0),
		];
	}//end addStep()

	/**
	 * Whether a status belongs to the case type, its own or inherited.
	 *
	 * @param string $caseTypeId The case type id.
	 * @param string $phaseId    The status id.
	 *
	 * @return bool True when it does.
	 */
	private function isPhaseOf(string $caseTypeId, string $phaseId): bool {
		foreach ($this->resolver->statusTypesFor(caseTypeId: $caseTypeId) as $statusType) {
			$id = (string) ($statusType['id'] ?? ($statusType['@self']['id'] ?? ''));
			if ($id !== '' && $id === $phaseId) {
				return true;
			}
		}

		return false;
	}//end isPhaseOf()

	/**
	 * The case type's draft definition with the highest version, or null.
	 *
	 * @param string $caseTypeId The case type id.
	 *
	 * @return array<string, mixed>|null The draft.
	 */
	private function newestDraft(string $caseTypeId): ?array {
		foreach ($this->definitions->listVersionsForCaseType(caseTypeId: $caseTypeId) as $row) {
			if ($this->guard->statusOf(row: $row) === WorkflowLifecycleGuard::STATUS_DRAFT) {
				return $row;
			}
		}

		return null;
	}//end newestDraft()

	/**
	 * The decoded steps of a definition.
	 *
	 * @param array<string, mixed> $definition The definition.
	 *
	 * @return array<int, array<string, mixed>> The steps that are objects.
	 */
	private function stepsOf(array $definition): array {
		$steps = ($definition['steps'] ?? []);
		if (is_string($steps) === true) {
			$steps = json_decode($steps, true);
		}

		if (is_array($steps) === false) {
			return [];
		}

		return array_values(array_filter($steps, 'is_array'));
	}//end stepsOf()

	/**
	 * The highest order among a phase's steps, 0 when it has none.
	 *
	 * @param array<int, array<string, mixed>> $steps   The steps.
	 * @param string                           $phaseId The status id.
	 *
	 * @return int The order.
	 */
	private function lastOrderIn(array $steps, string $phaseId): int {
		$last = 0;
		foreach ($steps as $step) {
			if ((string) ($step['status'] ?? '') === $phaseId) {
				$last = max($last, (int) ($step['order'] ?? 0));
			}
		}

		return $last;
	}//end lastOrderIn()

	/**
	 * A fresh version 4 uuid for a step.
	 *
	 * @return string The uuid.
	 */
	private function uuid(): string {
		$hex     = $this->random->generate(32, '0123456789abcdef');
		$hex[12] = '4';
		$hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

		return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
	}//end uuid()

	/**
	 * A list of group names, with the blanks and the non-strings dropped.
	 *
	 * @param mixed $value The raw list.
	 *
	 * @return array<int, string> The names.
	 */
	private function names(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$names = [];
		foreach ($value as $entry) {
			if (is_string($entry) === true && trim($entry) !== '') {
				$names[] = trim($entry);
			}
		}

		return array_values(array_unique($names));
	}//end names()
}//end class
