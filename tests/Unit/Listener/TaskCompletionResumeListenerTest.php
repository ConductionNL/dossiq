<?php

/**
 * A completed task resumes its run — and only when it should.
 *
 * WHAT THESE TESTS DO AND DO NOT PROVE. The authorization RULE lives in
 * OpenRegister (`FlowRunAssignee`) and is tested there, including a mutation
 * check. dossiq's suite resolves OpenRegister to stubs, so re-testing the rule
 * here would test a stub against itself — a second implementation validated by
 * a second fake, drifting from the real one while both suites stay green.
 *
 * So what is proven here is the half that IS dossiq's: that the listener asks
 * the rule at all, and that it OBEYS a refusal. The double below is told what
 * to answer, and the assertions are about what the listener then does.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-flow-human-steps/specs/task-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\TaskCompletionResumeListener;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Flow\FlowRunAssignee;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TaskCompletionResumeListenerTest extends TestCase {

	/**
	 * Records whether signal() was called, and with what.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $signals = [];

	/**
	 * The answer the injected assignee rule gives.
	 *
	 * @var boolean
	 */
	private bool $mayAnswer = true;

	protected function setUp(): void {
		$this->signals = [];
		$this->mayAnswer = true;
	}//end setUp()

	/**
	 * A task object, as the event carries it.
	 *
	 * @param array $overrides Fields to set or override.
	 *
	 * @return array The task.
	 */
	private function task(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'task-1',
				'status' => 'completed',
				'flowRun' => 'run-1',
				'flowNode' => 'ask-indiener',
			],
			$overrides
		);
	}//end task()

	/**
	 * An update event carrying a before and after state.
	 *
	 * @param array      $new The task after.
	 * @param array|null $old The task before.
	 *
	 * @return ObjectUpdatedEvent The event.
	 */
	private function event(array $new, ?array $old): ObjectUpdatedEvent {
		$newEntity = $this->createMock(ObjectEntity::class);
		$newEntity->method('getObject')->willReturn($new);

		$oldEntity = null;
		if ($old !== null) {
			$oldEntity = $this->createMock(ObjectEntity::class);
			$oldEntity->method('getObject')->willReturn($old);
		}

		$event = $this->createMock(ObjectUpdatedEvent::class);
		$event->method('getNewObject')->willReturn($newEntity);
		$event->method('getOldObject')->willReturn($oldEntity);

		return $event;
	}//end event()

	/**
	 * The listener, wired to doubles that record what it does.
	 *
	 * @param string|null $uid The acting user.
	 *
	 * @return TaskCompletionResumeListener The listener.
	 */
	private function listener(?string $uid = 'alice'): TaskCompletionResumeListener {
		$run = new FlowRun();
		$run->setUuid('run-1');

		$runs = $this->createMock(FlowRunMapper::class);
		$runs->method('findByUuid')->willReturn($run);

		$runner = new class($this->signals) extends FlowRunService {
			public function __construct(private array &$sink) {
			}

			public function signal(FlowRun $run, array $payload = []): ?FlowRun {
				$this->sink[] = ['run' => $run->getUuid(), 'payload' => $payload];

				return $run;
			}
		};

		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		$assignees = new class($this->mayAnswer) extends FlowRunAssignee {
			public function __construct(private bool &$answer) {
			}

			public function mayAnswer(FlowRun $run, ?string $uid): bool {
				return $this->answer;
			}
		};

		return new TaskCompletionResumeListener(
			runs: $runs,
			runner: $runner,
			userSession: $session,
			groups: $this->createMock(IGroupManager::class),
			logger: $this->createMock(LoggerInterface::class),
			assignees: $assignees
		);
	}//end listener()

	/**
	 * The old state is `active`, not `available`: the task schema's CMMN
	 * lifecycle (REQ-TASK-002) refuses a one-step available → completed
	 * update, so the only completion event the store can emit comes from an
	 * active task. The seeded states here mirror that.
	 */
	public function testCompletingAFlowTaskResumesItsRun(): void {
		$this->listener()->handle(
			$this->event($this->task(), $this->task(['status' => 'active']))
		);

		$this->assertCount(1, $this->signals);
		$this->assertSame('run-1', $this->signals[0]['run']);
		$this->assertSame('completed', $this->signals[0]['payload']['decision']);
		$this->assertSame('ask-indiener', $this->signals[0]['payload']['node']);
	}//end testCompletingAFlowTaskResumesItsRun()

	/**
	 * 🔴 THE SECURITY TEST. A refusal from the rule withholds the resume.
	 *
	 * This path calls FlowRunService::signal() directly and therefore does NOT
	 * inherit OpenRegister's HTTP resume guard. Without the check here, any user
	 * who can write a task object could advance somebody else's decision.
	 */
	public function testARefusalFromTheAssigneeRuleWithholdsTheResume(): void {
		$this->mayAnswer = false;

		$this->listener(uid: 'mallory')->handle(
			$this->event($this->task(), $this->task(['status' => 'active']))
		);

		$this->assertSame([], $this->signals, 'The run must not advance for somebody who was not asked.');
	}//end testARefusalFromTheAssigneeRuleWithholdsTheResume()

	public function testATaskWithNoRunResumesNothing(): void {
		$this->listener()->handle(
			$this->event(
				$this->task(['flowRun' => '', 'flowNode' => '']),
				$this->task(['flowRun' => '', 'flowNode' => '', 'status' => 'active'])
			)
		);

		$this->assertSame([], $this->signals);
	}//end testATaskWithNoRunResumesNothing()

	/**
	 * A task naming a run but no node cannot say which question it answers, so
	 * it resumes nothing rather than guessing.
	 */
	public function testATaskWithARunButNoNodeResumesNothing(): void {
		$this->listener()->handle(
			$this->event(
				$this->task(['flowNode' => '']),
				$this->task(['flowNode' => '', 'status' => 'active'])
			)
		);

		$this->assertSame([], $this->signals);
	}//end testATaskWithARunButNoNodeResumesNothing()

	/**
	 * 🔴 Editing an ALREADY-completed task does not resume the run again.
	 *
	 * Any later edit — a typo fixed in the description — is still an update
	 * whose status reads `completed`. Resuming on the state rather than on the
	 * transition would advance the run a second time.
	 */
	public function testEditingAnAlreadyCompletedTaskDoesNotResumeAgain(): void {
		$this->listener()->handle(
			$this->event(
				$this->task(['title' => 'fixed a typo']),
				$this->task()
			)
		);

		$this->assertSame([], $this->signals);
	}//end testEditingAnAlreadyCompletedTaskDoesNotResumeAgain()

	/**
	 * An update that does not complete the task resumes nothing.
	 */
	public function testAnUnrelatedUpdateResumesNothing(): void {
		$this->listener()->handle(
			$this->event(
				$this->task(['status' => 'active']),
				$this->task(['status' => 'available'])
			)
		);

		$this->assertSame([], $this->signals);
	}//end testAnUnrelatedUpdateResumesNothing()

	/**
	 * With no previous state the transition cannot be established, so nothing
	 * is resumed — the safer of the two possible mistakes, since the other one
	 * re-signals on every write to a completed task.
	 */
	public function testAnUpdateWithNoPreviousStateResumesNothing(): void {
		$this->listener()->handle($this->event($this->task(), null));

		$this->assertSame([], $this->signals);
	}//end testAnUpdateWithNoPreviousStateResumesNothing()

	/**
	 * A vanished run is not an error for the person completing the task.
	 */
	public function testATaskWhoseRunHasGoneStillCompletesQuietly(): void {
		$runs = $this->createMock(FlowRunMapper::class);
		$runs->method('findByUuid')->willThrowException(new \RuntimeException('gone'));

		$runner = new class($this->signals) extends FlowRunService {
			public function __construct(private array &$sink) {
			}

			public function signal(FlowRun $run, array $payload = []): ?FlowRun {
				$this->sink[] = ['run' => $run->getUuid(), 'payload' => $payload];

				return $run;
			}
		};

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$listener = new TaskCompletionResumeListener(
			runs: $runs,
			runner: $runner,
			userSession: $session,
			groups: $this->createMock(IGroupManager::class),
			logger: $this->createMock(LoggerInterface::class)
		);

		$listener->handle($this->event($this->task(), $this->task(['status' => 'active'])));

		$this->assertSame([], $this->signals);
	}//end testATaskWhoseRunHasGoneStillCompletesQuietly()
}//end class
