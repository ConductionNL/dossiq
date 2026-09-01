<?php

/**
 * Unit tests for DossiqAskPersonNode — create the task, then wait.
 *
 * 🔴 THE TEST THAT MATTERS MOST IS THE HEARTBEAT ONE. This node suspends with a
 * resume time as a safety net against a lost signal, which means it is
 * re-entered on a timer with no answer present. Creating the task
 * unconditionally would leave one task per heartbeat in somebody's list — every
 * one of them able to resume the run, all but one of them noise. Nothing about
 * the resulting tasks would look malformed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Flow;

use OCA\Dossiq\Flow\DossiqAskPersonNode;
use OCA\Dossiq\Service\FlowRunAsScope;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use UnexpectedValueException;

class DossiqAskPersonNodeTest extends TestCase {

	/**
	 * Every task the object service was asked to write.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $written = [];

	/**
	 * The uids the object service's runAs seam was asked to act as.
	 *
	 * @var string[]
	 */
	private array $actedAs = [];

	protected function setUp(): void {
		$this->written = [];
		$this->actedAs = [];
	}//end setUp()

	/**
	 * The node, wired to a recording object service.
	 *
	 * The fake's saveObject returns an ObjectEntity BECAUSE THE REAL ONE DOES.
	 * This fake used to return an array — the caller's own wrong assumption —
	 * so the suite proved the node could read a shape production never
	 * produces, and stayed green while every live save was followed by
	 * "could not identify the task it created". A fake that agrees with the
	 * caller cannot fail.
	 *
	 * @return DossiqAskPersonNode The node under test.
	 */
	private function node(): DossiqAskPersonNode {
		$objectService = new class($this->written, $this->actedAs) {
			public function __construct(private array &$sink, private array &$actedAs) {
			}

			public function saveObject(array $object, string $register, string $schema): ObjectEntity {
				$this->sink[] = $object;

				$entity = new ObjectEntity();
				$entity->setUuid('task-' . count($this->sink));
				$entity->setObject($object);

				return $entity;
			}

			public function runAs(IUser $user, callable $operation): mixed {
				$this->actedAs[] = $user->getUID();

				return $operation();
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($key === 'register' ? 'dossiq' : 'task')
		);

		$adminUser = $this->createMock(IUser::class);
		$adminUser->method('getUID')->willReturn('admin');
		$adminUser->method('isEnabled')->willReturn(true);

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(
			static fn (string $uid): ?IUser => ($uid === 'admin') ? $adminUser : null
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new DossiqAskPersonNode($settings, new FlowRunAsScope($settings, $users), $l10n, new NullLogger());
	}//end node()

	/**
	 * A valid configuration.
	 *
	 * @return array<string, mixed> The config.
	 */
	private function config(): array {
		return [
			'question' => 'Vul uw aanvraag aan',
			'assignee' => 'alice',
			'dueInDays' => '14',
		];
	}//end config()

	/**
	 * One item carrying a case.
	 *
	 * @return array<int, array<string, mixed>> The items.
	 */
	private function items(): array {
		return [['json' => ['id' => 'case-1', 'title' => 'Dakkapel']]];
	}//end items()

	/**
	 * A run context with this node's resume slot.
	 *
	 * @param FlowNodeResumeState $resume The slot.
	 *
	 * @return array<string, mixed> The context.
	 */
	private function context(FlowNodeResumeState $resume): array {
		return [
			FlowNodeResumeState::CONTEXT_KEY => $resume,
			FlowRunContext::CONTEXT_RUN => 'run-abc',
		];
	}//end context()

	public function testItCreatesTheTaskAndSuspends(): void {
		$resume = new FlowNodeResumeState('ask-indiener');

		try {
			$this->node()->execute($this->items(), $this->config(), $this->context($resume));
			self::fail('The node must suspend while the task is outstanding.');
		} catch (FlowSuspension $suspension) {
			self::assertStringContainsString('Vul uw aanvraag aan', $suspension->getMessage());
		}

		self::assertCount(1, $this->written);
		self::assertSame('Vul uw aanvraag aan', $this->written[0]['title']);
		self::assertSame('case-1', $this->written[0]['case']);
		self::assertSame('alice', $this->written[0]['assignee']);
		self::assertSame('available', $this->written[0]['status']);
	}//end testItCreatesTheTaskAndSuspends()

	/**
	 * 🔴 THE SAVE RESULT IS AN ObjectEntity, AND ITS UUID IS THE TASK ID.
	 *
	 * The node used to check `is_array($created)` against a service that
	 * returns an entity, so every SUCCESSFUL save was followed by "could not
	 * identify the task it created": the run STOPPED instead of suspending,
	 * the resume slot was never written, and the task sat orphaned. This
	 * asserts the whole contract — suspension (not a stop), and a resume slot
	 * carrying the entity's uuid so the completed task can wake the run.
	 */
	public function testTheSavedTaskIsIdentifiedByItsEntityUuid(): void {
		$resume = new FlowNodeResumeState('ask-indiener');

		try {
			$this->node()->execute($this->items(), $this->config(), $this->context($resume));
			self::fail('The node must suspend while the task is outstanding.');
		} catch (FlowSuspension $suspension) {
			// Suspended, not stopped: the save result was understood.
		}

		self::assertTrue($resume->has('taskId'), 'The resume slot must remember the task, or every heartbeat writes another.');
		self::assertSame('task-1', $resume->get('taskId'), 'The remembered id is the saved entity\'s uuid.');
	}//end testTheSavedTaskIsIdentifiedByItsEntityUuid()

	/**
	 * 🔴 THE TASK IS WRITTEN AS THE RUN'S ACTING IDENTITY.
	 *
	 * Under FlowRunWorker the ambient session carries nobody, so a bare
	 * saveObject() is refused as 'Anonymous' — measured live while the run
	 * context carried `runAs: admin`. Remove the runAs wrap in persistTask()
	 * and this goes red: the seam is never asked and $actedAs stays empty.
	 */
	public function testTheTaskIsWrittenAsTheRunsActingIdentity(): void {
		$resume = new FlowNodeResumeState('ask-indiener');
		$context = array_merge($this->context($resume), ['runAs' => 'admin']);

		try {
			$this->node()->execute($this->items(), $this->config(), $context);
		} catch (FlowSuspension $e) {
			// expected: the task is outstanding
		}

		self::assertSame(['admin'], $this->actedAs, 'The write must run through the object service\'s runAs seam.');
		self::assertCount(1, $this->written, 'And the task must actually have been written under it.');
	}//end testTheTaskIsWrittenAsTheRunsActingIdentity()

	/**
	 * An acting identity that resolves to nobody refuses the step rather than
	 * falling back to an anonymous write.
	 */
	public function testAnUnresolvableActingIdentityRefuses(): void {
		$resume = new FlowNodeResumeState('ask-indiener');
		$context = array_merge($this->context($resume), ['runAs' => 'ghost']);

		$this->expectException(RuntimeException::class);

		try {
			$this->node()->execute($this->items(), $this->config(), $context);
		} finally {
			self::assertSame([], $this->written, 'Nothing may be written under an identity that does not resolve.');
		}
	}//end testAnUnresolvableActingIdentityRefuses()

	/**
	 * 🔴 A TEMPLATED ASSIGNEE IS RENDERED AGAINST THE CASE.
	 *
	 * The shipped declaration says `{{ case.assignee }}` because a flow cannot
	 * name a real person — the uid differs per case. The engine templates only
	 * inside its own nodes, so this node renders the value itself. Storing the
	 * literal is what orphaned every applicant task live: the resume guard
	 * compared real uids against the placeholder text and refused all of them.
	 */
	public function testATemplatedAssigneeIsRenderedAgainstTheCase(): void {
		$resume = new FlowNodeResumeState('ask-indiener');
		$items = [['json' => ['id' => 'case-1', 'title' => 'Dakkapel', 'assignee' => 'alice']]];
		$config = array_merge($this->config(), ['assignee' => '{{ case.assignee }}']);

		try {
			$this->node()->execute($items, $config, $this->context($resume));
		} catch (FlowSuspension $e) {
			// expected: the task is outstanding
		}

		self::assertCount(1, $this->written);
		self::assertSame('alice', $this->written[0]['assignee'], 'The task must carry the RENDERED assignee.');
		self::assertSame('alice', $resume->get('assignee'), 'So must the resume slot the guard reads back.');
	}//end testATemplatedAssigneeIsRenderedAgainstTheCase()

	/**
	 * An assignee template that resolves to nothing refuses LOUDLY. A quiet
	 * empty assignee would create a task ANYONE can answer, because
	 * OpenRegister's resume guard treats silence as "no restriction".
	 */
	public function testAnAssigneeTemplateThatResolvesToNothingRefuses(): void {
		$resume = new FlowNodeResumeState('ask-indiener');
		$config = array_merge($this->config(), ['assignee' => '{{ case.assignee }}']);

		$this->expectException(RuntimeException::class);

		try {
			// The case names no assignee, so the placeholder resolves empty.
			$this->node()->execute($this->items(), $config, $this->context($resume));
		} finally {
			self::assertSame([], $this->written, 'No task may be created for an assignee that resolved to nobody.');
		}
	}//end testAnAssigneeTemplateThatResolvesToNothingRefuses()

	/**
	 * 🔑 The task names the run AND the node — both are needed to resume.
	 */
	public function testTheTaskCarriesTheRunAndTheNodeThatAskedIt(): void {
		$resume = new FlowNodeResumeState('ask-indiener');

		try {
			$this->node()->execute($this->items(), $this->config(), $this->context($resume));
		} catch (FlowSuspension $e) {
			// expected
		}

		self::assertSame('run-abc', $this->written[0]['flowRun']);
		self::assertSame('ask-indiener', $this->written[0]['flowNode']);
	}//end testTheTaskCarriesTheRunAndTheNodeThatAskedIt()

	/**
	 * 🔴 THE HEARTBEAT MUST NOT CREATE A SECOND TASK.
	 */
	public function testAHeartbeatDoesNotCreateAnotherTask(): void {
		$resume = new FlowNodeResumeState('ask-indiener');
		$node = $this->node();

		foreach ([1, 2, 3] as $ignored) {
			try {
				$node->execute($this->items(), $this->config(), $this->context($resume));
			} catch (FlowSuspension $e) {
				// expected on every pass while unanswered
			}
		}

		self::assertCount(1, $this->written, 'One task per question, however many times the run wakes.');
	}//end testAHeartbeatDoesNotCreateAnotherTask()

	/**
	 * An answer passes through and lands on every item.
	 */
	public function testAnAnswerIsCarriedOntoTheItems(): void {
		$resume = new FlowNodeResumeState('ask-indiener', ['taskId' => 'task-1', 'askedAt' => 'now']);

		$context = array_merge(
			$this->context($resume),
			['signal' => ['decision' => 'completed', 'completedBy' => 'alice']]
		);

		$out = $this->node()->execute($this->items(), array_merge($this->config(), ['signalKey' => 'aanvulling']), $context);

		self::assertCount(1, $out);
		self::assertSame('completed', $out[0]['json']['aanvulling']['decision']);
		self::assertSame([], $this->written, 'Answering must not write another task.');
	}//end testAnAnswerIsCarriedOntoTheItems()

	/**
	 * A resume with no decision is a NUDGE, not an answer.
	 */
	public function testAResumeWithNoDecisionSuspendsAgain(): void {
		$resume = new FlowNodeResumeState('ask-indiener', ['taskId' => 'task-1', 'askedAt' => 'now']);

		$context = array_merge($this->context($resume), ['signal' => ['note' => 'just poking']]);

		$this->expectException(FlowSuspension::class);
		$this->node()->execute($this->items(), $this->config(), $context);
	}//end testAResumeWithNoDecisionSuspendsAgain()

	/**
	 * 🔴 An unassigned question would be answerable by ANYONE, because
	 * OpenRegister's resume guard treats silence as "no restriction".
	 */
	public function testAConfigWithNoAssigneeIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);

		$this->node()->validateConfig(['question' => 'Something?']);
	}//end testAConfigWithNoAssigneeIsRefused()

	public function testAConfigWithNoQuestionIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);

		$this->node()->validateConfig(['assignee' => 'alice']);
	}//end testAConfigWithNoQuestionIsRefused()

	/**
	 * Without a resume slot the step cannot be made idempotent, so it refuses
	 * rather than writing a task it will duplicate on the next heartbeat.
	 */
	public function testWithoutAResumeSlotItRefuses(): void {
		$this->expectException(RuntimeException::class);

		$this->node()->execute($this->items(), $this->config(), [FlowRunContext::CONTEXT_RUN => 'run-abc']);
	}//end testWithoutAResumeSlotItRefuses()

	public function testWithNoCaseItRefuses(): void {
		$this->expectException(RuntimeException::class);

		$this->node()->execute([['json' => []]], $this->config(), $this->context(new FlowNodeResumeState('n')));
	}//end testWithNoCaseItRefuses()

	/**
	 * A node over an object service whose saveObject returns whatever the
	 * test says, so the OTHER result shapes createdTaskId() accepts stay
	 * honest: each shape below is one a duck-typed service can legitimately
	 * hand back, and each must still identify the task.
	 *
	 * @param mixed $result What saveObject returns.
	 *
	 * @return DossiqAskPersonNode The node under test.
	 */
	private function nodeReturning(mixed $result): DossiqAskPersonNode {
		$objectService = new class($this->written, $result) {
			public function __construct(private array &$sink, private mixed $result) {
			}

			public function saveObject(array $object, string $register, string $schema): mixed {
				$this->sink[] = $object;

				return $this->result;
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($key === 'register' ? 'dossiq' : 'task')
		);

		$users = $this->createMock(IUserManager::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new DossiqAskPersonNode($settings, new FlowRunAsScope($settings, $users), $l10n, new NullLogger());
	}//end nodeReturning()

	/**
	 * A LEGACY ARRAY result still identifies the task. The service is
	 * duck-typed, and refusing a shape that carries a perfectly good id
	 * would recreate the orphaned-task bug for the other shape.
	 */
	public function testALegacyArraySaveResultStillIdentifiesTheTask(): void {
		$resume = new FlowNodeResumeState('ask-indiener');
		$node = $this->nodeReturning(['id' => 'legacy-task-7']);

		try {
			$node->execute($this->items(), $this->config(), $this->context($resume));
		} catch (FlowSuspension $e) {
			// expected: the task is outstanding
		}

		self::assertSame('legacy-task-7', $resume->get('taskId'));
	}//end testALegacyArraySaveResultStillIdentifiesTheTask()

	/**
	 * An entity with NO uuid falls back to its serialised form, which the
	 * real ObjectEntity guarantees carries a top-level id.
	 */
	public function testAnEntityWithoutAUuidFallsBackToItsSerialisedId(): void {
		$entity = new ObjectEntity();
		$entity->setObject(['id' => 'serialised-task-9', 'title' => 'x']);

		$resume = new FlowNodeResumeState('ask-indiener');
		$node = $this->nodeReturning($entity);

		try {
			$node->execute($this->items(), $this->config(), $this->context($resume));
		} catch (FlowSuspension $e) {
			// expected: the task is outstanding
		}

		self::assertSame('serialised-task-9', $resume->get('taskId'));
	}//end testAnEntityWithoutAUuidFallsBackToItsSerialisedId()

	/**
	 * A result that names NOTHING refuses: an unidentifiable task means an
	 * empty resume slot, so the next heartbeat would write a duplicate.
	 */
	public function testAResultNamingNoIdRefuses(): void {
		$resume = new FlowNodeResumeState('ask-indiener');
		$node = $this->nodeReturning('not-a-result-shape');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('could not identify');

		$node->execute($this->items(), $this->config(), $this->context($resume));
	}//end testAResultNamingNoIdRefuses()

	public function testItAnnouncesItsIdentity(): void {
		$node = $this->node();

		self::assertSame('dossiq.askPerson', $node->getId());
		self::assertNotSame('', $node->getDisplayName());
		self::assertNotSame('', $node->getDescription());
	}//end testItAnnouncesItsIdentity()
}//end class
