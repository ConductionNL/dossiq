<?php

/**
 * The task-completion resume listener on events it does not own and on an engine that fails outright.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\TaskCompletionResumeListener;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCA\OpenRegister\Service\Flow\FlowRunSignalService;
use OCA\OpenRegister\Service\Flow\RunSignal;
use OCP\EventDispatcher\Event;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Edge paths of TaskCompletionResumeListener.
 *
 * @covers \OCA\Dossiq\Listener\TaskCompletionResumeListener
 */
class TaskCompletionResumeListenerEdgesTest extends TestCase {

	/**
	 * The signal seam.
	 *
	 * @var FlowRunSignalService&MockObject
	 */
	private FlowRunSignalService&MockObject $signals;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->signals = $this->createMock(FlowRunSignalService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Any other event signals nothing.
	 *
	 * @return void
	 */
	public function testAnotherEventSignalsNothing(): void {
		$this->signals->expects(self::never())->method('answerByUuid');

		$this->listener()->handle(new Event());
	}//end testAnotherEventSignalsNothing()

	/**
	 * A task that cannot be read signals nothing and logs nothing.
	 *
	 * @return void
	 */
	public function testAnUnreadableTaskSignalsNothing(): void {
		$this->signals->expects(self::never())->method('answerByUuid');
		$this->logger->expects(self::never())->method('error');

		$event = $this->createMock(TaskTerminalEvent::class);
		$event->method('isCommitted')->willReturn(true);
		$event->method('getTask')->willThrowException(new RuntimeException('task row gone'));

		$this->listener()->handle($event);
	}//end testAnUnreadableTaskSignalsNothing()

	/**
	 * An engine failure that is not a refusal is logged as an error with the run and task.
	 *
	 * @return void
	 */
	public function testAnEngineFailureIsLoggedAsAnError(): void {
		$this->signals->expects(self::once())->method('answerByUuid')
			->with('run-1', self::isInstanceOf(RunSignal::class))
			->willThrowException(new RuntimeException('engine down'));
		$this->logger->expects(self::once())->method('error')->with(
			'Dossiq: could not resume flow run run-1 after its task was completed',
			['error' => 'engine down', 'task' => 'task-1'],
		);

		$this->listener()->handle($this->completed());
	}//end testAnEngineFailureIsLoggedAsAnError()

	/**
	 * Nobody signed in resumes the run with an empty actor in the payload.
	 *
	 * @return void
	 */
	public function testNobodySignedInResumesWithoutAnActor(): void {
		$this->signals->expects(self::once())->method('answerByUuid')->with(
			'run-1',
			self::callback(static fn (RunSignal $signal): bool => $signal->actorUid === null && $signal->payload['completedBy'] === '' && $signal->nodeId === 'ask')
		);

		$this->listener()->handle($this->completed());
	}//end testNobodySignedInResumesWithoutAnActor()

	/**
	 * The listener, with nobody signed in.
	 *
	 * @return TaskCompletionResumeListener The listener.
	 */
	private function listener(): TaskCompletionResumeListener {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		return new TaskCompletionResumeListener(signals: $this->signals, userSession: $session, logger: $this->logger);
	}//end listener()

	/**
	 * A committed completion of a flow task.
	 *
	 * @return TaskTerminalEvent The event.
	 */
	private function completed(): TaskTerminalEvent {
		$task = $this->createMock(Task::class);
		$task->method('getRunUuid')->willReturn('run-1');
		$task->method('getNodeId')->willReturn('ask');
		$task->method('getState')->willReturn('completed');
		$task->method('getUuid')->willReturn('task-1');

		$event = $this->createMock(TaskTerminalEvent::class);
		$event->method('getTask')->willReturn($task);
		$event->method('isCommitted')->willReturn(true);

		return $event;
	}//end completed()
}//end class
