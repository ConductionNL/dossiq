<?php

/**
 * An inspection run is an OpenRegister Task on the case.
 *
 * Change inspection-checklists-onto-task, task 4.2 (design D2, D3). Submitting a run
 * checks it against the frozen template first, then creates one task of kind
 * `inspection` on the case (template, version and snapshot, the inspector as
 * assignee, location and offline facts in metadata) and completes it with
 * the answers as responses, the photos as evidence and the outcome. A refused
 * run writes nothing.
 *
 * Reading a case's runs asks the task inbox for that case's inspection tasks
 * and answers them in the shape the inspection panel reads.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Inspection
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
 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-rapport-creation
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Inspection;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Task\EngineInboxQuery;
use RuntimeException;
use Throwable;

/**
 * Submits and reads inspection runs as tasks.
 *
 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-rapport-creation
 */
class InspectionRunService {
	use SearchesObjects;

	/**
	 * The task kind every run carries.
	 *
	 * @var string
	 */
	public const KIND = 'inspection';

	/**
	 * OpenRegister's task lifecycle, resolved by name.
	 *
	 * @var string
	 */
	public const TASK_SERVICE = 'OCA\\OpenRegister\\Service\\Task\\TaskService';

	/**
	 * How many runs one case answers.
	 *
	 * @var integer
	 */
	private const LIMIT = 200;

	/**
	 * Metadata keys a request may set (design D3).
	 *
	 * @var array<int, string>
	 */
	private const METADATA = ['location', 'capturedOffline', 'capturedAt', 'inspection'];

	/**
	 * The answers and the outcome rule.
	 *
	 * @var InspectionAnswers
	 */
	private InspectionAnswers $answers;

	/**
	 * Constructor.
	 *
	 * @param SettingsService  $settingsService Bridge to OpenRegister and config.
	 * @param EngineInboxQuery $inbox           The task inbox read.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly EngineInboxQuery $inbox,
	) {
		$this->answers = new InspectionAnswers();
	}//end __construct()

	/**
	 * Submit one run: check it, create its task, complete it.
	 *
	 * @param string               $caseId     The case uuid.
	 * @param string               $templateId The template uuid, or the uuid of the older checklist it was folded from.
	 * @param array<string, mixed> $payload    The request body.
	 * @param string               $actor      The inspector's uid.
	 *
	 * @return array<string, mixed> The run, as {@see runsForCase()} answers one.
	 *
	 * @throws RuntimeException When the template is unknown, the run breaks a rule, or the task layer is absent.
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-rapport-creation
	 */
	public function submit(string $caseId, string $templateId, array $payload, string $actor): array {
		$template = $this->template(templateId: $templateId);
		$answers = $this->answers->fromPayload(payload: $payload);
		$violations = $this->answers->violations(snapshot: $template, answers: $answers);
		if ($violations !== []) {
			throw new RuntimeException(implode(' ', $violations));
		}

		$tasks = $this->settingsService->getOpenRegisterClass(class: self::TASK_SERVICE);
		if ($tasks === null) {
			throw new RuntimeException('The OpenRegister task layer is not available');
		}

		$task = $tasks->create(data: $this->taskData(caseId: $caseId, template: $template, payload: $payload, actor: $actor), actor: $actor);
		$completed = $tasks->complete(
			uuid: (string)$task->getUuid(),
			outcome: $this->answers->outcome(snapshot: $template, answers: $answers),
			resultText: null,
			comment: $this->stringOrNull(value: ($payload['remarks'] ?? null)),
			actor: $actor,
			responses: $answers,
			evidence: $this->answers->evidence(answers: $answers),
		);

		return $this->asRun(task: $completed->jsonSerialize());
	}//end submit()

	/**
	 * Every run on a case, newest last.
	 *
	 * Read as the acting user with the inbox's all-scope, the same read
	 * {@see \OCA\Dossiq\Service\Task\EngineTaskInbox::forCase()} makes: the
	 * caller has passed dossiq's case read check before this runs.
	 *
	 * @param string $caseId The case uuid.
	 * @param string $actor  The reading uid.
	 *
	 * @return array<int, array<string, mixed>> The runs.
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-results-are-stored-under-the-schemas-own-property-names
	 */
	public function runsForCase(string $caseId, string $actor): array {
		if (trim($caseId) === '' || trim($actor) === '') {
			return [];
		}

		$rows = $this->inbox->rows(
			criteria: [
				'uid' => $actor,
				'isAdmin' => true,
				'scope' => $this->inbox->scope(name: 'SCOPE_ALL'),
				'objectUuid' => $caseId,
				'kind' => self::KIND,
			],
			limit: self::LIMIT,
			failure: ['Dossiq: could not read the inspection runs of a case', ['case' => $caseId]]
		);

		$runs = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$runs[] = $this->asRun(task: $row);
			}
		}

		usort($runs, static fn (array $a, array $b): int => strcmp((string)$a['inspectionDate'], (string)$b['inspectionDate']));

		return $runs;
	}//end runsForCase()

	/**
	 * A task in the shape the panel and the results endpoint read.
	 *
	 * @param array<string, mixed> $task The serialised task.
	 *
	 * @return array<string, mixed> The run.
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-panel-on-case-dashboard
	 */
	public function asRun(array $task): array {
		$responses = ($task['responses'] ?? []);
		if (is_array($responses) === false) {
			$responses = [];
		}

		$metadata = ($task['metadata'] ?? []);
		if (is_array($metadata) === false) {
			$metadata = [];
		}

		$moment = (string)($task['completedAt'] ?? $metadata['legacyCompletedAt'] ?? $task['created'] ?? '');

		return [
			'id' => (string)($task['uuid'] ?? ''),
			'case' => (string)($task['objectUuid'] ?? ''),
			'checklist' => (string)($task['templateId'] ?? ''),
			'templateVersion' => $task['templateVersion'] ?? null,
			'inspector' => (string)($task['completedBy'] ?? $task['assignee'] ?? ''),
			'completedBy' => (string)($task['completedBy'] ?? ''),
			'inspectionDate' => $moment,
			'result' => (string)($task['outcome'] ?? ''),
			'failedItems' => $this->answers->failedCount(answers: $responses),
			'items' => array_map(fn (array $answer): array => $this->answers->forPanel(answer: $answer), array_values(array_filter($responses, 'is_array'))),
			'remarks' => (string)($task['comment'] ?? ''),
			'location' => ($metadata['location'] ?? null),
			'photos' => ($task['evidence'] ?? []),
			'state' => (string)($task['state'] ?? ''),
		];
	}//end asRun()

	/**
	 * The fields of the run's task.
	 *
	 * @param string               $caseId   The case uuid.
	 * @param array<string, mixed> $template The frozen template.
	 * @param array<string, mixed> $payload  The request body.
	 * @param string               $actor    The inspector.
	 *
	 * @return array<string, mixed> The task fields.
	 */
	private function taskData(string $caseId, array $template, array $payload, string $actor): array {
		$metadata = [];
		foreach (self::METADATA as $key) {
			if (array_key_exists($key, $payload) === true && $payload[$key] !== null && $payload[$key] !== '') {
				$metadata[$key] = $payload[$key];
			}
		}

		$data = [
			'title' => 'Inspectie: ' . (string)($template['name'] ?? ''),
			'kind' => self::KIND,
			'appId' => 'dossiq',
			'objectUuid' => $caseId,
			'assignee' => $actor,
			'state' => 'active',
			'templateId' => (string)($template['@self']['id'] ?? $template['id'] ?? ''),
			'templateVersion' => (int)($template['version'] ?? 1),
			'templateSnapshot' => $template,
			'startAt' => (string)($payload['inspectionDate'] ?? date(format: 'c')),
		];
		if ($metadata !== []) {
			$data['metadata'] = $metadata;
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: 'case_schema');
		if ($register !== '' && $schema !== '') {
			$data['registerId'] = (int)$register;
			$data['schemaId'] = (int)$schema;
		}

		return $data;
	}//end taskData()

	/**
	 * The template a run is filled against.
	 *
	 * The older endpoints name the checklist they knew, so an id that is not a
	 * template is looked up as the source a template was folded from.
	 *
	 * @param string $templateId The template uuid, or an older checklist uuid.
	 *
	 * @return array<string, mixed> The template.
	 *
	 * @throws RuntimeException When nothing answers to it.
	 */
	private function template(string $templateId): array {
		$objects = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue(key: 'register');
		if ($objects === null || $register === '') {
			throw new RuntimeException('OpenRegister is not available');
		}

		try {
			$template = $this->findObjectAsArray(objectService: $objects, register: $register, schema: InspectionTemplateMapper::SCHEMA, id: $templateId);
		} catch (Throwable) {
			// Not a template uuid; the older-checklist lookup below decides.
			$template = null;
		}

		if ($template !== null) {
			return $template;
		}

		foreach (['inspectieChecklist/', 'inspectionChecklist/'] as $prefix) {
			$found = $this->searchObjectsAsArrays(
				objectService: $objects,
				register: $register,
				schema: InspectionTemplateMapper::SCHEMA,
				filters: ['legacyRef' => $prefix . $templateId, '_limit' => 1]
			);
			if ($found !== []) {
				return $found[0];
			}
		}

		throw new RuntimeException('Unknown checklist template: ' . $templateId);
	}//end template()

	/**
	 * A trimmed string, or null when empty.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The string.
	 */
	private function stringOrNull(mixed $value): ?string {
		$text = trim((string)$value);
		if ($text === '') {
			return null;
		}

		return $text;
	}//end stringOrNull()
}//end class
