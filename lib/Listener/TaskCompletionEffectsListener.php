<?php

/**
 * A completed task does what its case type said completing it does.
 *
 * The effect belongs to the task definition, not to the handler remembering.
 * So when the engine announces a task terminal, this runs the effects the case
 * type declared on that task and publishes the files it was holding to the
 * case.
 *
 * WHY IT LISTENS INSTEAD OF SITTING IN THE COMPLETION CALL. A task can be
 * completed from the case page, from the task page, from the inbox and over
 * OpenRegister's own API, and dossiq is in the path of only the first. An
 * effect that ran on one surface and not the others would be a feature that
 * works until somebody uses the app a different way.
 * {@see \OCA\Dossiq\Service\Task\CaseTaskActions} pre-checks where dossiq
 * owns the surface, so an unresolvable effect refuses the completion before it
 * happens; this runs whatever surface it came from.
 *
 * WHY A FAILURE HERE CANNOT REFUSE. The task is already completed and
 * committed by the time this runs, so a refusal would mean "the task is
 * completed and the letter was not sent". That is recorded loudly rather than
 * hidden, and it is the reason the pre-check exists at all.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Task\TaskAttachmentService;
use OCA\Dossiq\Service\Task\TaskEffects;
use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs a completed task's declared effects and publishes its files.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
 */
class TaskCompletionEffectsListener implements IEventListener {

	use SearchesObjects;

	/**
	 * The terminal state that means the work was done.
	 *
	 * `terminated` and `disabled` are the work being withdrawn, and running a
	 * send effect on a cancelled task would post a letter nobody decided to
	 * send. The engine fires this event for all three.
	 *
	 * @var string
	 */
	private const STATUS_COMPLETED = 'completed';

	/**
	 * Constructor.
	 *
	 * @param TaskEffects           $effects     Reads and runs what the task declared.
	 * @param TaskAttachmentService $attachments Publishes the files it was holding.
	 * @param SettingsService       $settings    Reads the case the task is on.
	 * @param IUserSession          $userSession Names who completed it.
	 * @param LoggerInterface       $logger      The logger.
	 */
	public function __construct(
		private readonly TaskEffects $effects,
		private readonly TaskAttachmentService $attachments,
		private readonly SettingsService $settings,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Do what completing this task was declared to do.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof TaskTerminalEvent) === false || $event->isCommitted() === false) {
			return;
		}

		$task = $this->completedTask(event: $event);
		if ($task === null) {
			return;
		}

		$caseId = (string)$task['case'];
		if ($caseId === '') {
			// A task on no case has no case to act on. Not an error: an
			// engine task can belong to something that is not a dossiq case.
			return;
		}

		$this->runEffects(task: $task, caseId: $caseId);
		$this->publishFiles(taskId: (string)$task['id'], caseId: $caseId);
	}//end handle()

	/**
	 * Run the declared effects against the case.
	 *
	 * @param array<string, mixed> $task   The completed task.
	 * @param string               $caseId The case it is on.
	 *
	 * @return void
	 */
	private function runEffects(array $task, string $caseId): void {
		$declared = $this->effects->declaredOn(task: $task);
		if ($declared === []) {
			return;
		}

		$case = $this->readCase(caseId: $caseId);
		if ($case === null) {
			$this->logger->error(
				'Dossiq: a completed task declared effects, and its case could not be read to run them',
				['task' => $task['id'], 'case' => $caseId]
			);

			return;
		}

		$this->effects->run(
			effects: $declared,
			case: $case,
			context: [
				'caseId' => $caseId,
				'taskId' => (string)$task['id'],
				'userId' => (string)($this->userSession->getUser()?->getUID() ?? ''),
				'transitionLabel' => (string)$task['title'],
			]
		);
	}//end runEffects()

	/**
	 * Turn the files the task was holding into documents on the case.
	 *
	 * @param string $taskId The completed task.
	 * @param string $caseId The case.
	 *
	 * @return void
	 */
	private function publishFiles(string $taskId, string $caseId): void {
		try {
			$this->attachments->publish(caseId: $caseId, taskId: $taskId);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: a completed task could not publish its files to the case',
				['task' => $taskId, 'case' => $caseId, 'error' => $e->getMessage()]
			);
		}
	}//end publishFiles()

	/**
	 * The task this event completed, as an array, or null when it is not one.
	 *
	 * @param TaskTerminalEvent $event The engine's terminal event.
	 *
	 * @return array<string, mixed>|null The task.
	 */
	private function completedTask(TaskTerminalEvent $event): ?array {
		try {
			$entity = $event->getTask();
			$task = [
				'id' => (string)($entity->getUuid() ?? ''),
				'title' => (string)($entity->getTitle() ?? ''),
				'status' => (string)($entity->getState() ?? ''),
				'case' => (string)($entity->getObjectUuid() ?? ''),
				'metadata' => (($entity->getMetadata() ?? [])),
			];
		} catch (Throwable) {
			return null;
		}

		if ($task['status'] !== self::STATUS_COMPLETED || $task['id'] === '') {
			return null;
		}

		return $task;
	}//end completedTask()

	/**
	 * Read the case a task is on, or null.
	 *
	 * @param string $caseId The case.
	 *
	 * @return array<string, mixed>|null The case.
	 */
	private function readCase(string $caseId): ?array {
		$objectService = $this->settings->getObjectService();
		$register = $this->settings->getConfigValue(key: 'register');
		$schema = $this->settings->getConfigValue(key: 'case_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return null;
		}

		try {
			return $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $caseId
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: the case of a completed task could not be read',
				['case' => $caseId, 'error' => $e->getMessage()]
			);

			return null;
		}
	}//end readCase()
}//end class
