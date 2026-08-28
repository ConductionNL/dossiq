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
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCP\IL10N;
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

	protected function setUp(): void {
		$this->written = [];
	}//end setUp()

	/**
	 * The node, wired to a recording object service.
	 *
	 * @return DossiqAskPersonNode The node under test.
	 */
	private function node(): DossiqAskPersonNode {
		$objectService = new class($this->written) {
			public function __construct(private array &$sink) {
			}

			public function saveObject(array $object, string $register, string $schema): array {
				$this->sink[] = $object;

				return array_merge($object, ['id' => 'task-' . count($this->sink)]);
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($key === 'register' ? 'dossiq' : 'task')
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new DossiqAskPersonNode($settings, $l10n, new NullLogger());
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

	public function testItAnnouncesItsIdentity(): void {
		$node = $this->node();

		self::assertSame('dossiq.askPerson', $node->getId());
		self::assertNotSame('', $node->getDisplayName());
		self::assertNotSame('', $node->getDescription());
	}//end testItAnnouncesItsIdentity()
}//end class
