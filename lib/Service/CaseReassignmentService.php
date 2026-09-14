<?php

/**
 * Dossiq CaseReassignmentService.
 *
 * Permanent bulk transfer of a handler's open workload to another handler —
 * the coordinator-driven counterpart of temporary substitution. Preview is
 * non-mutating; execute reassigns each open case/task in a single previewed,
 * audited batch (per-item audit entry sharing a batch id, per-item
 * success/failure, a single digest notification to the receiving handler).
 * Closed and archived cases are never touched.
 *
 * The coordinator-role guard is enforced at the controller surface; this
 * service performs the data work.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTime;
use InvalidArgumentException;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\BulkAction\ReassignCasesAction;
use OCA\Dossiq\Service\Bulk\BulkJobHandoff;
use OCA\Dossiq\Service\Support\WritesReassignments;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Task\EngineTaskGateway;
use OCA\Dossiq\Service\Task\EngineTaskInbox;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * Builds the selection a caseload release acts on, and hands it to the job.
 *
 * The loop that used to live here is gone: OpenRegister's bulk job walks the
 * cases, records what it did to each and reports it, and a coordinator reads
 * the rehearsal before committing (D-1, D-6).
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */
class CaseReassignmentService {

	use WritesReassignments;
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings/config + ObjectService bridge.
	 * @param BulkJobHandoff $handoff The hand-off to OpenRegister's bulk job.
	 * @param EngineTaskInbox $engineTasks The engine's task reader.
	 * @param EngineTaskGateway $engineTask The engine's task verbs.
	 * @param IManager $notificationManager The Nextcloud notification manager.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly BulkJobHandoff $handoff,
		private readonly EngineTaskInbox $engineTasks,
		private readonly EngineTaskGateway $engineTask,
		private readonly IManager $notificationManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Preview the open cases and tasks that a reassignment from a handler would
	 * affect. Strictly read-only.
	 *
	 * @param string $fromUser The departing handler user id.
	 * @param array<string, mixed>|null $filter Optional filter, e.g. ['caseType' => 'uuid'].
	 *
	 * @return array{cases: array<int, array<string, mixed>>, tasks: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	public function preview(string $fromUser, ?array $filter = null): array {
		$fromUser = trim($fromUser);
		if ($fromUser === '') {
			throw new InvalidArgumentException('fromUser is required');
		}

		[$objectService, $register] = $this->context();
		$caseSchema = (string)$this->settingsService->getConfigValue('case_schema');
		$caseType = '';
		if (isset($filter['caseType']) === true) {
			$caseType = (string)$filter['caseType'];
		}

		$finalIds = $this->finalStatusIds(objectService: $objectService, register: $register);

		$cases = [];
		if ($caseSchema !== '') {
			$caseResults = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $caseSchema,
				filters: ['assignee' => $fromUser]
			);

			$cases = $this->filterOpenCases(caseResults: $caseResults, finalIds: $finalIds, caseType: $caseType);
		}

		// The ENGINE holds the tasks, and it holds the open/closed split too:
		// `openForAssignee` asks for `isTerminal: false` rather than reading
		// a page and dropping the closed rows from it, which would have lost
		// every open task past the page boundary.
		$tasks = $this->filterOpenTasks(
			taskResults: $this->engineTasks->openForAssignee(actor: $fromUser),
			cases: $cases,
			caseType: $caseType
		);

		return ['cases' => $cases, 'tasks' => $tasks];
	}//end preview()

	/**
	 * Keep only the non-final cases, optionally narrowed to one case type.
	 *
	 * @param array<int, array<string, mixed>> $caseResults The raw case search results.
	 * @param array<int, string> $finalIds Status ids marking a case as closed/archived.
	 * @param string $caseType Optional caseType uuid to narrow by ('' = all).
	 *
	 * @return array<int, array<string, mixed>> The open cases, in search order.
	 */
	private function filterOpenCases(array $caseResults, array $finalIds, string $caseType): array {
		$cases = [];

		foreach ($caseResults as $case) {
			if (in_array((string)($case['status'] ?? ''), $finalIds, true) === true) {
				continue;
			}

			if ($caseType !== '' && (string)($case['caseType'] ?? '') !== $caseType) {
				continue;
			}

			$cases[] = $case;
		}

		return $cases;
	}//end filterOpenCases()

	/**
	 * Keep only the open tasks, optionally narrowed to the previewed cases.
	 *
	 * @param array<int, array<string, mixed>> $taskResults The raw task search results.
	 * @param array<int, array<string, mixed>> $cases The previewed cases the tasks may belong to.
	 * @param string $caseType Optional caseType uuid to narrow by ('' = all).
	 *
	 * @return array<int, array<string, mixed>> The open tasks, in search order.
	 */
	private function filterOpenTasks(array $taskResults, array $cases, string $caseType): array {
		$caseIds = [];
		foreach ($cases as $c) {
			$caseIds[(string)($c['id'] ?? ($c['uuid'] ?? ''))] = true;
		}

		$tasks = [];

		// NO TERMINAL-STATUS FILTER HERE ANY MORE. The engine is asked for
		// `isTerminal: false` and answers only open tasks, so a second copy
		// of the same three state names would be one more thing to keep in
		// step with `Task::STATES` and nothing else.
		foreach ($taskResults as $task) {
			// When narrowed by caseType, only tasks belonging to a previewed
			// case are in scope.
			if ($caseType !== '' && isset($caseIds[(string)($task['case'] ?? '')]) === false) {
				continue;
			}

			$tasks[] = $task;
		}

		return $tasks;
	}//end filterOpenTasks()

	/**
	 * Release a handler's caseload, as one bulk job.
	 *
	 * Builds the selection and hands it over. There is no loop here: the job
	 * walks the cases, records what it did to each and reports it, and a
	 * coordinator commits it after reading the rehearsal (D-6). What comes
	 * back is the PREVIEWED job; nothing on a case has been written yet.
	 *
	 * ⚠️ The departing handler's open TASKS are moved straight away, in this
	 * call, and are not part of the job. A flow task is the engine's own
	 * record with its own audit row and its own `reassign` verb: it is not an
	 * object in the case register, so the job cannot walk it. Leaving the
	 * tasks behind until somebody commits is the failure this gesture exists
	 * to prevent, so they move with the request that orders the release.
	 *
	 * @param string                    $fromUser      The departing handler.
	 * @param string                    $toUser        The receiving handler.
	 * @param string                    $justification Why the caseload is being moved.
	 * @param array<string, mixed>|null $filter        Optional filter, e.g. ['caseType' => 'uuid'].
	 * @param string                    $actorId       The acting coordinator.
	 *
	 * @return array{job: array<string, mixed>, tasks: array<int, array<string, mixed>>}
	 *         The previewed job over the cases, and what happened to the tasks.
	 *
	 * @throws InvalidArgumentException When a handler, the receiver or the reason is missing.
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function releaseCaseload(
		string $fromUser,
		string $toUser,
		string $justification,
		?array $filter = null,
		string $actorId = '',
	): array {
		$fromUser = trim($fromUser);
		$toUser = trim($toUser);
		$justification = trim($justification);

		if ($fromUser === '' || $toUser === '') {
			throw new InvalidArgumentException('Both fromUser and toUser are required');
		}

		if ($fromUser === $toUser) {
			throw new InvalidArgumentException('Cannot reassign a handler to themselves');
		}

		if ($justification === '') {
			throw new InvalidArgumentException('A written justification is required');
		}

		$preview = $this->preview(fromUser: $fromUser, filter: $filter);
		$batchId = $this->generateBatchId();

		$caseIds = [];
		foreach ($preview['cases'] as $case) {
			$id = (string)($case['id'] ?? ($case['uuid'] ?? ''));
			if ($id !== '') {
				$caseIds[] = $id;
			}
		}

		$job = [];
		if ($caseIds !== []) {
			$job = $this->handoff->create(
				actionId: ReassignCasesAction::ID,
				parameters: ['toUser' => $toUser, 'reason' => $justification, 'batchId' => $batchId],
				selection: ['ids' => $caseIds],
				justification: $justification,
				actorUid: $actorId,
			);
		}

		$tasks = $this->releaseTasks(tasks: $preview['tasks'], toUser: $toUser, actorId: $actorId);

		$this->logger->info(
			'Dossiq caseload release handed to a bulk job',
			[
				'batchId' => $batchId,
				'from' => $fromUser,
				'to' => $toUser,
				'actor' => $actorId,
				'cases' => count($caseIds),
				'tasks' => count($tasks),
			]
		);

		if ($tasks !== []) {
			$this->notifyDigest(toUser: $toUser, fromUser: $fromUser, count: count($tasks), batchId: $batchId);
		}

		return ['job' => $job, 'tasks' => $tasks];
	}//end releaseCaseload()

	/**
	 * Hand each open task to the engine's own reassign verb.
	 *
	 * A VERB, not a field write. The engine stamps the acting identity and
	 * writes its own audit row, so the transfer does not depend on a
	 * hand-rolled entry in a JSON `activity` blob that only this app reads.
	 *
	 * @param array<int, array<string, mixed>> $tasks   The departing handler's open tasks.
	 * @param string                           $toUser  The receiving handler.
	 * @param string                           $actorId The acting coordinator.
	 *
	 * @return array<int, array<string, mixed>> What happened to each task.
	 */
	private function releaseTasks(array $tasks, string $toUser, string $actorId): array {
		$results = [];
		foreach ($tasks as $task) {
			$id = (string)($task['id'] ?? ($task['uuid'] ?? ''));
			$results[] = [
				'id' => $id,
				'title' => (string)($task['title'] ?? ''),
				'success' => $this->engineTask->reassign(taskId: $id, assignee: $toUser, actor: $actorId),
			];
		}

		return $results;
	}//end releaseTasks()





	/**
	 * Send a single digest notification to the receiving handler.
	 *
	 * @param string $toUser Receiving handler.
	 * @param string $fromUser Departing handler.
	 * @param int $count Number of items transferred.
	 * @param string $batchId The batch id.
	 *
	 * @return void
	 */
	private function notifyDigest(string $toUser, string $fromUser, int $count, string $batchId): void {
		try {
			$notification = $this->notificationManager->createNotification();
			$notification->setApp(Application::APP_ID)
				->setUser($toUser)
				->setDateTime(new DateTime())
				->setObject('reassignment', $batchId)
				->setSubject(
					'cases_reassigned',
					['fromUser' => $fromUser, 'count' => $count]
				);
			$this->notificationManager->notify($notification);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Reassignment digest notification failed',
				['toUser' => $toUser, 'batchId' => $batchId, 'error' => $e->getMessage()]
			);
		}
	}//end notifyDigest()

	/**
	 * Resolve the set of final statusType ids (closed/archived cases).
	 *
	 * @param object $objectService The ObjectService.
	 * @param string $register Register id.
	 *
	 * @return array<int, string>
	 */
	private function finalStatusIds(object $objectService, string $register): array {
		$statusTypeSchema = (string)$this->settingsService->getConfigValue('status_type_schema');
		if ($statusTypeSchema === '') {
			return [];
		}

		try {
			$rows = $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $statusTypeSchema);
		} catch (\Throwable $e) {
			return [];
		}

		$ids = [];
		foreach ($rows as $row) {
			$isFinal = ($row['isFinal'] ?? false);
			if (in_array($isFinal, [true, 'true', 1], true) === true) {
				$ids[] = (string)($row['id'] ?? ($row['uuid'] ?? ''));
			}
		}

		return array_values(array_filter($ids));
	}//end finalStatusIds()


}//end class
