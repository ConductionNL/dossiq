<?php

/**
 * Dossiq task listener registrar.
 *
 * The two listeners that run when the flow engine says a task reached its
 * terminal state: one resumes the run the task was blocking, one performs
 * what the case type declared completing it does.
 *
 * Their own registrar, and not part of the workflow one, for the same reason
 * every other subject here has one: the termijn listeners, the decision
 * listener and these three answer to different apps and fail in different
 * ways. Both are pure observers (ADR-022).
 *
 * @category AppInfo
 * @package  OCA\Dossiq\AppInfo\Registrar
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
 * @spec openspec/specs/task-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\AppInfo\Registrar;

use OCA\Dossiq\Listener\TaskCompletionEffectsListener;
use OCA\Dossiq\Listener\TaskCompletionResumeListener;
use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the listeners that run when a task is completed.
 *
 * @spec openspec/specs/task-management/spec.md
 */
class TaskListenerRegistrar {

	/**
	 * Register the listeners that resume a run when its task is completed.
	 *
	 * A task is an OpenRegister `Task` row owned by the flow engine, and the
	 * engine announces its own terminality: `TaskService` dispatches
	 * `TaskTerminalEvent` once the terminal write has committed.
	 *
	 * Registered unconditionally: unlike the decision events,
	 * `TaskTerminalEvent` is OpenRegister's own and OpenRegister is a hard
	 * dependency of this app. The class ships from openregister v2.0.13 onward
	 * (openregister#3269), and `FlowRunSignalService::signalAs()`, which the
	 * listener signals through, from openregister#3332.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/task-management/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		// The ENGINE's terminal event, not an object update. Tasks are
		// OpenRegister `Task` rows now, so nothing writes a `caseTask` object
		// and an ObjectUpdatedEvent listener would never fire again: the run
		// would only resume on DossiqAskPersonNode's 30-minute heartbeat, and
		// a wedge that recovers half an hour late still reads as a wedge.
		$context->registerEventListener(
			event: TaskTerminalEvent::class,
			listener: TaskCompletionResumeListener::class
		);

		// The SAME event, a second listener, deliberately. Resuming the run a
		// task was blocking and doing what the case type declared completing
		// it does are two different jobs with two different failure modes: a
		// refused signal is an authorization answer, a failed effect is a
		// letter that was not sent. One listener doing both would have to
		// decide which failure silences the other.
		//
		// It listens rather than living in the completion call because a task
		// can be completed from the case page, the task page, the inbox or
		// OpenRegister's own API, and dossiq is in the path of only the first.
		$context->registerEventListener(
			event: TaskTerminalEvent::class,
			listener: TaskCompletionEffectsListener::class
		);
	}//end register()
}//end class
