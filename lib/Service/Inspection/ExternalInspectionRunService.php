<?php

/**
 * An external inspector's run is an OpenRegister portal task.
 *
 * Change inspection-checklists-onto-task, task 4.3 (design D6). A checklist
 * run given to an inspector without a Nextcloud account is one task of kind
 * `inspection` on the case with performer type `external` and the inspector's
 * party reference as assignee. That is the shape OpenRegister's portal task
 * seam lists for a portal subject, so the run shows in the inspector's own
 * task list without a collection of its own.
 *
 * The task names dossiq's portal action in `metadata.completeVia`, so
 * OpenRegister refuses to complete it any other way. That action lands in
 * {@see self::complete()}: the subject comes from the verified assertion only,
 * the task's stored party reference is compared with it before anything is
 * read from the answers, the answers are checked against the frozen template,
 * and only then does OpenRegister complete the task. A refused run writes
 * nothing.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Inspection
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * @spec openspec/changes/inspection-checklists-onto-task/design.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Inspection;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Kernel\Contracts\ExternalInspectionRunInterface;
use OCA\Dossiq\Kernel\Contracts\InspectionRunServiceInterface;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Exception\TaskConflictException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Assigns external inspection runs and completes them for their portal subject.
 *
 * @spec openspec/changes/inspection-checklists-onto-task/design.md
 */
class ExternalInspectionRunService implements ExternalInspectionRunInterface {

	/**
	 * OpenRegister's portal task seam, resolved by name.
	 *
	 * @var string
	 */
	public const PORTAL_TASKS = 'OCA\\OpenRegister\\Service\\Portal\\PortalTaskService';

	/**
	 * OpenRegister's portal subject, built from the verified assertion.
	 *
	 * @var string
	 */
	public const SUBJECT = 'OCA\\OpenRegister\\Service\\Portal\\PortalSubject';

	/**
	 * The audience, tenant, trust and session of that subject.
	 *
	 * @var string
	 */
	public const SUBJECT_CLAIMS = 'OCA\\OpenRegister\\Service\\Portal\\PortalSubjectClaims';

	/**
	 * What the subject submitted, as OpenRegister completes it.
	 *
	 * @var string
	 */
	public const COMPLETION = 'OCA\\OpenRegister\\Service\\Portal\\PortalCompletion';

	/**
	 * OpenRegister's prefix for a party acting through the portal.
	 *
	 * @var string
	 */
	public const PARTY_PREFIX = 'party:';

	/**
	 * The performer type of a portal party's task.
	 *
	 * @var string
	 */
	public const PERFORMER = 'external';

	/**
	 * The app named in `completeVia` and passed as `viaApp`.
	 *
	 * @var string
	 */
	private const APP = 'dossiq';

	/**
	 * The sentence of a task that is absent or not the subject's to complete.
	 *
	 * @var string
	 */
	private const NOT_FOUND = 'This inspection task does not exist.';

	/**
	 * The longest subject reference accepted.
	 *
	 * @var integer
	 */
	private const MAX_REF = 255;

	/**
	 * The answers and the outcome rule.
	 *
	 * @var InspectionAnswers
	 */
	private InspectionAnswers $answers;

	/**
	 * Constructor.
	 *
	 * @param SettingsService               $settingsService Bridge to OpenRegister and config.
	 * @param InspectionRunServiceInterface $runs            The template lookup and the run shape.
	 * @param LoggerInterface               $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly InspectionRunServiceInterface $runs,
		private readonly LoggerInterface $logger,
	) {
		$this->answers = new InspectionAnswers();
	}//end __construct()

	/**
	 * Give an external inspector a checklist to run on a case.
	 *
	 * The caller has passed dossiq's case mutation check. The task is created
	 * open (`active`) with the frozen template, so a later template edit does
	 * not change what the inspector is asked.
	 *
	 * @param string               $caseId       The case uuid.
	 * @param string               $templateId   The template uuid, or the uuid of the older checklist it was folded from.
	 * @param string               $inspectorRef The external inspector's portal subject reference.
	 * @param array<string, mixed> $payload      The request body (`inspection`, `dueAt`).
	 * @param string               $actor        The uid assigning the run.
	 *
	 * @return array<string, mixed> The open run.
	 *
	 * @throws RuntimeException When the reference is missing, the template is unknown or the task layer is absent.
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/design.md
	 */
	public function assign(string $caseId, string $templateId, string $inspectorRef, array $payload, string $actor): array {
		$ref = $this->subjectRef(value: $inspectorRef);
		if ($ref === null) {
			throw new RuntimeException('inspectorRef is required');
		}

		$template = $this->runs->templateFor(templateId: $templateId);
		$tasks = $this->settingsService->getOpenRegisterClass(class: InspectionRunServiceInterface::TASK_SERVICE);
		if ($tasks === null) {
			throw new RuntimeException('The OpenRegister task layer is not available');
		}

		$data = $this->taskData(caseId: $caseId, template: $template, party: self::PARTY_PREFIX . $ref, payload: $payload);
		$task = $tasks->create(data: $data, actor: $actor);

		return $this->runs->asRun(task: $task->jsonSerialize());
	}//end assign()

	/**
	 * Complete an external inspection task for the portal subject it is assigned to.
	 *
	 * Order, fail-closed: the subject from the claims (401 without one), the
	 * task as the seam shows it to that subject, then dossiq's own check that
	 * it is an external inspection task whose stored party reference is the
	 * subject's (404 otherwise, the same answer as a task that does not
	 * exist), then the answers against the frozen template (422), then the
	 * completion. Nothing is written before the last step.
	 *
	 * @param array<string, mixed> $claims  The verified assertion claims.
	 * @param string               $taskId  The task uuid.
	 * @param array<string, mixed> $payload The answers and remarks.
	 *
	 * @return array{status: int, body: array<string, mixed>} The HTTP status and body.
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/design.md
	 */
	public function complete(array $claims, string $taskId, array $payload): array {
		$ref = $this->subjectRef(value: (string)($claims['sub'] ?? ''));
		if ($ref === null) {
			return ['status' => 401, 'body' => ['error' => 'unauthorized']];
		}

		$taskId = trim($taskId);
		if ($taskId === '') {
			return ['status' => 400, 'body' => ['error' => 'invalid', 'message' => 'taskId is required']];
		}

		$portal = $this->portalTasks();
		if ($portal === null) {
			return ['status' => 503, 'body' => ['error' => 'unavailable']];
		}

		$subject = $this->subject(ref: $ref, claims: $claims);
		try {
			$task = $this->ownTask(portal: $portal, subject: $subject, taskId: $taskId, party: self::PARTY_PREFIX . $ref);
		} catch (RefusedException $e) {
			return ['status' => $e->getStatus(), 'body' => ['error' => 'not_found']];
		}

		return $this->completeOwnTask(portal: $portal, subject: $subject, task: $task, payload: $payload);
	}//end complete()

	/**
	 * Check the answers against the frozen template, then complete the task.
	 *
	 * @param object               $portal  OpenRegister's portal task seam.
	 * @param object               $subject The portal subject.
	 * @param array<string, mixed> $task    The serialised task, already proven the subject's.
	 * @param array<string, mixed> $payload The answers and remarks.
	 *
	 * @return array{status: int, body: array<string, mixed>} The HTTP status and body.
	 */
	private function completeOwnTask(object $portal, object $subject, array $task, array $payload): array {
		$snapshot = $task['templateSnapshot'] ?? [];
		if (is_array($snapshot) === false) {
			$snapshot = [];
		}

		// A portal party has no files in this instance, so a file id it names
		// is not evidence of anything: photos are dropped, and an item whose
		// gate needs one is refused below with the gate's own message.
		$answers = array_map(
			static function (array $answer): array {
				unset($answer['photos']);
				return $answer;
			},
			$this->answers->fromPayload(payload: $payload)
		);
		$violations = $this->answers->violations(snapshot: $snapshot, answers: $answers);
		if ($violations !== []) {
			return ['status' => 422, 'body' => ['error' => 'invalid', 'message' => implode(' ', $violations)]];
		}

		// OpenRegister stores the answers as the task's responses unchanged; its
		// docblock names a map, the run's responses are the list the internal
		// submit writes too (InspectionRunService), so both read back alike.
		$completionClass = self::COMPLETION;
		$completion = new $completionClass(
			// @phpstan-ignore argument.type (a list of answers, stored as given)
			answers: $answers,
			comment: $this->stringOrNull(value: ($payload['remarks'] ?? null)),
			files: [],
			outcome: $this->answers->outcome(snapshot: $snapshot, answers: $answers)
		);

		try {
			$completed = $portal->complete(subject: $subject, uuid: (string)$task['uuid'], completion: $completion, viaApp: self::APP);
		} catch (Throwable $e) {
			return $this->refusedCompletion(taskId: (string)$task['uuid'], exception: $e);
		}

		return ['status' => 200, 'body' => $this->runs->asRun(task: $completed->jsonSerialize())];
	}//end completeOwnTask()

	/**
	 * The answer to a completion OpenRegister refused: a terminal task is a 409, anything else a logged 503.
	 *
	 * @param string    $taskId    The task.
	 * @param Throwable $exception The refusal.
	 *
	 * @return array{status: int, body: array<string, mixed>} The HTTP status and body.
	 */
	private function refusedCompletion(string $taskId, Throwable $exception): array {
		if ($exception instanceof TaskConflictException) {
			return ['status' => 409, 'body' => ['error' => 'conflict', 'message' => 'This checklist was already submitted.']];
		}

		$this->logger->warning(
			'Dossiq: an external inspection task could not be completed',
			['task' => $taskId, 'error' => $exception->getMessage()]
		);

		return ['status' => 503, 'body' => ['error' => 'unavailable']];
	}//end refusedCompletion()

	/**
	 * The task, when it is an external inspection task stored for this party.
	 *
	 * The seam already refuses a task that is not the subject's; the check is
	 * made again here on the stored values, because this endpoint is the one
	 * that decides, and a task of another kind is not a checklist to complete.
	 *
	 * @param object $portal  OpenRegister's portal task seam.
	 * @param object $subject The portal subject.
	 * @param string $taskId  The task uuid.
	 * @param string $party   The subject's party reference.
	 *
	 * @return array<string, mixed> The serialised task.
	 *
	 * @throws RefusedException A 404 when the task is absent or not the party's to complete.
	 */
	private function ownTask(object $portal, object $subject, string $taskId, string $party): array {
		try {
			$task = $portal->show(subject: $subject, uuid: $taskId)->jsonSerialize();
		} catch (Throwable $e) {
			// Absent, not this subject's, or held back: all read as absent.
			throw new RefusedException(rule: 'inspection-task-not-found', sentence: self::NOT_FOUND, status: 404, previous: $e);
		}

		if (is_array($task) === false
			|| (string)($task['kind'] ?? '') !== InspectionRunServiceInterface::KIND
			|| (string)($task['performerType'] ?? '') !== self::PERFORMER
			|| hash_equals($party, (string)($task['assignee'] ?? '')) === false
		) {
			throw new RefusedException(rule: 'inspection-task-not-found', sentence: self::NOT_FOUND, status: 404);
		}

		$task['uuid'] = (string)($task['uuid'] ?? $taskId);

		return $task;
	}//end ownTask()

	/**
	 * OpenRegister's portal task seam, or null when OpenRegister or its portal classes are absent.
	 *
	 * @return object|null The seam.
	 */
	private function portalTasks(): ?object {
		foreach ([self::SUBJECT, self::SUBJECT_CLAIMS, self::COMPLETION] as $class) {
			if (class_exists($class) === false) {
				return null;
			}
		}

		return $this->settingsService->getOpenRegisterClass(class: self::PORTAL_TASKS);
	}//end portalTasks()

	/**
	 * OpenRegister's portal subject for the verified claims.
	 *
	 * @param string               $ref    The subject reference.
	 * @param array<string, mixed> $claims The verified assertion claims.
	 *
	 * @return object The subject.
	 */
	private function subject(string $ref, array $claims): object {
		$claimsClass = self::SUBJECT_CLAIMS;
		$subjectClass = self::SUBJECT;

		return new $subjectClass(
			subjectRef: $ref,
			claims: new $claimsClass(
				audience: (string)($claims['audience'] ?? ''),
				organisation: (string)($claims['organisation'] ?? ''),
				trust: (string)($claims['trust'] ?? ''),
				jti: (string)($claims['jti'] ?? '')
			)
		);
	}//end subject()

	/**
	 * The fields of an external inspection task.
	 *
	 * @param string               $caseId   The case uuid.
	 * @param array<string, mixed> $template The frozen template.
	 * @param string               $party    The inspector's party reference.
	 * @param array<string, mixed> $payload  The request body.
	 *
	 * @return array<string, mixed> The task fields.
	 */
	private function taskData(string $caseId, array $template, string $party, array $payload): array {
		$data = [
			'title' => 'Inspectie: ' . (string)($template['name'] ?? ''),
			'kind' => InspectionRunServiceInterface::KIND,
			'appId' => self::APP,
			'objectUuid' => $caseId,
			'performerType' => self::PERFORMER,
			'assignee' => $party,
			'state' => 'active',
			'templateId' => (string)($template['@self']['id'] ?? $template['id'] ?? ''),
			'templateVersion' => (int)($template['version'] ?? 1),
			'templateSnapshot' => $template,
			'metadata' => $this->metadata(party: $party, payload: $payload),
		];

		$dueAt = $this->stringOrNull(value: ($payload['dueAt'] ?? null));
		if ($dueAt !== null) {
			$data['dueAt'] = $dueAt;
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
	 * What the task records at create: the action that completes it, the party and the visit.
	 *
	 * @param string               $party   The inspector's party reference.
	 * @param array<string, mixed> $payload The request body.
	 *
	 * @return array<string, mixed> The metadata.
	 */
	private function metadata(string $party, array $payload): array {
		$metadata = [
			'completeVia' => ['app' => self::APP, 'action' => self::ACTION],
			'partyReference' => $party,
		];

		$inspection = $this->stringOrNull(value: ($payload['inspection'] ?? null));
		if ($inspection !== null) {
			$metadata['inspection'] = $inspection;
		}

		return $metadata;
	}//end metadata()

	/**
	 * A usable subject reference, or null: non-empty, no whitespace, not over-long.
	 *
	 * @param string $value The reference.
	 *
	 * @return string|null The trimmed reference.
	 */
	private function subjectRef(string $value): ?string {
		$ref = trim($value);
		if ($ref === '' || strlen($ref) > self::MAX_REF || preg_match('/\s/', $ref) === 1) {
			return null;
		}

		return $ref;
	}//end subjectRef()

	/**
	 * A trimmed string, or null when empty or not scalar.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The string.
	 */
	private function stringOrNull(mixed $value): ?string {
		if (is_scalar($value) === false) {
			return null;
		}

		$text = trim((string)$value);
		if ($text === '') {
			return null;
		}

		return $text;
	}//end stringOrNull()
}//end class
