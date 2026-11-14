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

use OCA\Dossiq\Kernel\Contracts\InspectionRunServiceInterface;
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
class InspectionRunService implements InspectionRunServiceInterface {
	use SearchesObjects;

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

		$start = new InspectionRunStart(caseId: $caseId, template: $template, payload: $payload, actor: $actor);
		$task = $tasks->create(data: $this->taskData(start: $start), actor: $actor);
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
		$responses = $this->arrayOrEmpty(value: ($task['responses'] ?? []));
		$metadata = $this->arrayOrEmpty(value: ($task['metadata'] ?? []));

		$moment = (string)($task['completedAt'] ?? $metadata['legacyCompletedAt'] ?? $task['created'] ?? '');

		return $this->runHead(task: $task, moment: $moment) + $this->runFindings(task: $task, responses: $responses, metadata: $metadata);
	}//end asRun()

	/**
	 * The value when it is an array, else the empty array.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return array<mixed> The array.
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-panel-on-case-dashboard
	 */
	private function arrayOrEmpty(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return $value;
	}//end arrayOrEmpty()

	/**
	 * The run's identity, who did it, when and with what result.
	 *
	 * @param array<string, mixed> $task   The serialised task.
	 * @param string               $moment When it was completed.
	 *
	 * @return array<string, mixed> The leading fields of the run.
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-panel-on-case-dashboard
	 */
	private function runHead(array $task, string $moment): array {
		return [
			'id' => (string)($task['uuid'] ?? ''),
			'case' => (string)($task['objectUuid'] ?? ''),
			'checklist' => (string)($task['templateId'] ?? ''),
			'templateVersion' => $task['templateVersion'] ?? null,
			'inspector' => (string)($task['completedBy'] ?? $task['assignee'] ?? ''),
			'completedBy' => (string)($task['completedBy'] ?? ''),
			'inspectionDate' => $moment,
			'result' => (string)($task['outcome'] ?? ''),
		];
	}//end runHead()

	/**
	 * The run's answers, remarks, location, photos and state.
	 *
	 * @param array<string, mixed> $task      The serialised task.
	 * @param array<mixed>         $responses The answers.
	 * @param array<string, mixed> $metadata  The task metadata.
	 *
	 * @return array<string, mixed> The trailing fields of the run.
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-panel-on-case-dashboard
	 */
	private function runFindings(array $task, array $responses, array $metadata): array {
		return [
			'failedItems' => $this->answers->failedCount(answers: $responses),
			'items' => array_map(fn (array $answer): array => $this->answers->forPanel(answer: $answer), array_values(array_filter($responses, 'is_array'))),
			'remarks' => (string)($task['comment'] ?? ''),
			'location' => ($metadata['location'] ?? null),
			'photos' => ($task['evidence'] ?? []),
			'state' => (string)($task['state'] ?? ''),
		];
	}//end runFindings()

	/**
	 * The fields of the run's task.
	 *
	 * @param InspectionRunStart $start The case, the frozen template, the request body and the inspector.
	 *
	 * @return array<string, mixed> The task fields.
	 */
	private function taskData(InspectionRunStart $start): array {
		$metadata = $this->metadataOf(payload: $start->payload);

		$data = $this->baseTaskData(start: $start);
		if ($metadata !== []) {
			$data['metadata'] = $metadata;
		}

		return $this->withRegister(data: $data);
	}//end taskData()

	/**
	 * The metadata the request body carries, without empty values.
	 *
	 * @param array<string, mixed> $payload The request body.
	 *
	 * @return array<string, mixed> The metadata.
	 */
	private function metadataOf(array $payload): array {
		$metadata = [];
		foreach (self::METADATA as $key) {
			if (array_key_exists($key, $payload) === true && $payload[$key] !== null && $payload[$key] !== '') {
				$metadata[$key] = $payload[$key];
			}
		}

		return $metadata;
	}//end metadataOf()

	/**
	 * The task fields every run carries.
	 *
	 * @param InspectionRunStart $start The case, the frozen template, the request body and the inspector.
	 *
	 * @return array<string, mixed> The task fields.
	 */
	private function baseTaskData(InspectionRunStart $start): array {
		$template = $start->template;
		return [
			'title' => 'Inspectie: ' . (string)($template['name'] ?? ''),
			'kind' => self::KIND,
			'appId' => 'dossiq',
			'objectUuid' => $start->caseId,
			'assignee' => $start->actor,
			'state' => 'active',
			'templateId' => (string)($template['@self']['id'] ?? $template['id'] ?? ''),
			'templateVersion' => (int)($template['version'] ?? 1),
			'templateSnapshot' => $template,
			'startAt' => (string)($start->payload['inspectionDate'] ?? date(format: 'c')),
		];
	}//end baseTaskData()

	/**
	 * The task fields with the case's register and schema, when both are configured.
	 *
	 * @param array<string, mixed> $data The task fields.
	 *
	 * @return array<string, mixed> The task fields.
	 */
	private function withRegister(array $data): array {
		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: 'case_schema');
		if ($register !== '' && $schema !== '') {
			$data['registerId'] = (int)$register;
			$data['schemaId'] = (int)$schema;
		}

		return $data;
	}//end withRegister()

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

		$template = $this->templateById(objects: $objects, register: $register, templateId: $templateId);
		if ($template !== null) {
			return $template;
		}

		return $this->templateFoldedFrom(objects: $objects, register: $register, templateId: $templateId);
	}//end template()

	/**
	 * The template with this uuid, or null when it is not a template uuid.
	 *
	 * @param object $objects    OpenRegister's object service.
	 * @param string $register   The register id.
	 * @param string $templateId The template uuid, or an older checklist uuid.
	 *
	 * @return array<string, mixed>|null The template.
	 */
	private function templateById(object $objects, string $register, string $templateId): ?array {
		try {
			$template = $this->findObjectAsArray(objectService: $objects, register: $register, schema: InspectionTemplateMapper::SCHEMA, id: $templateId);
		} catch (Throwable) {
			// Not a template uuid; the older-checklist lookup below decides.
			$template = null;
		}

		return $template;
	}//end templateById()

	/**
	 * The template folded from an older checklist with this uuid.
	 *
	 * @param object $objects    OpenRegister's object service.
	 * @param string $register   The register id.
	 * @param string $templateId The older checklist uuid.
	 *
	 * @return array<string, mixed> The template.
	 *
	 * @throws RuntimeException When nothing answers to it.
	 */
	private function templateFoldedFrom(object $objects, string $register, string $templateId): array {
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
	}//end templateFoldedFrom()

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
