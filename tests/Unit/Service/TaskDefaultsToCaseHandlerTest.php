<?php

/**
 * One case, two paths, one principal.
 *
 * 🔴 THE TWO PATHS DRIFTED, AND ONLY ONE OF THEM WAS FIXED. `CreateTaskHandler`
 * read `case.assignee` for itself when nothing was authored, so a task created
 * by a status transition reached the case handler; `DossiqAskPersonNode` called
 * the same resolver, got '', and REFUSED. So the same case, the same silence in
 * the declaration, and two different outcomes: a task for the handler on one
 * path and a failed run on the other. A per-path test cannot see that, because
 * each one passes about its own half.
 *
 * This fixture pair is the control. It runs the two callers over ONE case and
 * asserts they land on the same person, which is the only assertion that fails
 * when the default moves back out of the resolver into one caller.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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
 * @spec openspec/changes/task-defaults-to-case-handler/specs/task-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Flow\DossiqAskPersonNode;
use OCA\Dossiq\Service\AssigneeResolver;
use OCA\Dossiq\Service\Task\EngineTaskGateway;
use OCA\Dossiq\Service\Transitions\CreateTaskHandler;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The transition handler and the flow node agree about who work goes to.
 *
 * @covers \OCA\Dossiq\Service\AssigneeResolver
 *
 * @uses \OCA\Dossiq\Flow\DossiqAskPersonNode
 * @uses \OCA\Dossiq\Service\Transitions\CreateTaskHandler
 */
class TaskDefaultsToCaseHandlerTest extends TestCase {

	/**
	 * Every task either caller asked the engine to write.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $written = [];

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->written = [];
	}//end setUp()

	/**
	 * The one case both paths run over: a handler, and nothing authored.
	 *
	 * @return array<string, mixed> The case.
	 */
	private static function theCase(): array {
		return ['id' => 'case-1', 'title' => 'Dakkapel', 'assignee' => 'alice'];
	}//end theCase()

	/**
	 * An engine that records what it was asked to write and reads nothing back.
	 *
	 * Reading nothing back is correct here: neither assertion is about a
	 * re-entry, and a task written in this test is never looked up again.
	 *
	 * @return EngineTaskGateway The double.
	 */
	private function engine(): EngineTaskGateway {
		return new class($this->written) extends EngineTaskGateway {
			/**
			 * @param array<int, array<string, mixed>> $sink Where writes land.
			 */
			public function __construct(private array &$sink) {
			}

			/**
			 * @return string The reason the engine is unusable, or ''.
			 */
			public function unavailableReason(): string {
				return '';
			}

			/**
			 * @return string The last error.
			 */
			public function lastError(): string {
				return 'the engine said no';
			}

			/**
			 * @param array<string, mixed> $task   The task.
			 * @param string               $caseId The case.
			 * @param string|null          $actor  The acting identity.
			 *
			 * @return string The new task id.
			 */
			public function mirrorImport(array $task, string $caseId, ?string $actor): string {
				$this->sink[] = $task;

				return 'task-' . count($this->sink);
			}

			/**
			 * @param string $taskId The task id.
			 *
			 * @return array<string, mixed>|null The task, or null.
			 */
			public function find(string $taskId): ?array {
				return null;
			}
		};
	}//end engine()

	/**
	 * A session naming a user, because the engine refuses a verb with no actor.
	 *
	 * Deliberately NOT the case handler: if either path ever fell back to
	 * whoever triggered the work, this fixture would show it as "sander".
	 *
	 * @return IUserSession The session.
	 */
	private function session(): IUserSession {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('sander');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return $session;
	}//end session()

	/**
	 * The assignee a status transition's `createTask` lands on.
	 *
	 * @param array<string, mixed> $actionConfig The action.
	 * @param array<string, mixed> $case         The case.
	 *
	 * @return array<string, mixed> The task the engine was handed.
	 */
	private function taskFromTransition(array $actionConfig, array $case): array {
		$handler = new CreateTaskHandler(
			new AssigneeResolver(new NullLogger()),
			$this->engine(),
			new NullLogger(),
			$this->session()
		);

		$handler->handle(actionConfig: $actionConfig, case: $case, transitionContext: []);

		return end($this->written);
	}//end taskFromTransition()

	/**
	 * The assignee a `dossiq.askPerson` flow step lands on.
	 *
	 * @param array<string, mixed> $config The step configuration.
	 * @param array<string, mixed> $case   The case.
	 *
	 * @return array<string, mixed> The task the engine was handed.
	 */
	private function taskFromFlowStep(array $config, array $case): array {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$node = new DossiqAskPersonNode(
			new AssigneeResolver(new NullLogger()),
			$l10n,
			new NullLogger(),
			$this->engine(),
			$this->session()
		);

		$resume = (new FlowResumeState([]))->forNode('ask-behandelaar');

		try {
			$node->execute(
				[['json' => $case]],
				$config,
				[
					FlowNodeResumeState::CONTEXT_KEY => $resume,
					FlowRunContext::CONTEXT_RUN => 'run-abc',
				]
			);
		} catch (FlowSuspension $suspension) {
			// Expected: the step waits while the task is outstanding.
		}

		return end($this->written);
	}//end taskFromFlowStep()

	/**
	 * What neither path resolves lands on the case handler on both.
	 *
	 * 🔴 THIS IS THE PAIR THAT USED TO DISAGREE, AND THE FLOW SIDE IS THE ONE
	 * THAT CHANGED. `{{ case.responsible }}` is authored, so the flow step is a
	 * valid declaration, and it renders to nothing on a case that fills no
	 * `responsible`. The transition wrote the task to the case handler; the
	 * flow step threw and the run FAILED. Same case, same silence, two
	 * outcomes.
	 *
	 * The step is authored rather than blank because `validateConfig()` refuses
	 * a blank `assignee` outright, and that is a separate rule about what a
	 * declaration may say, not about what it resolves to.
	 *
	 * @return void
	 */
	public function testBothPathsLandOnTheCaseHandler(): void {
		$fromTransition = $this->taskFromTransition(
			['type' => 'createTask', 'title' => 'Beoordeel', 'assignee' => '{{ case.responsible }}'],
			self::theCase()
		);
		$fromFlow = $this->taskFromFlowStep(
			['question' => 'Beoordeel', 'assignee' => '{{ case.responsible }}'],
			self::theCase()
		);

		self::assertSame('alice', $fromTransition['assignee']);
		self::assertSame('alice', $fromFlow['assignee']);
		self::assertSame(
			$fromTransition['assignee'],
			$fromFlow['assignee'],
			'A transition and a flow step must send the same case\'s work to the same person.'
		);
	}//end testBothPathsLandOnTheCaseHandler()

	/**
	 * An action with no assignee at all reaches the handler too.
	 *
	 * The transition side only: a flow step must name somebody to be a valid
	 * declaration at all, which `validateConfig()` enforces.
	 *
	 * @return void
	 */
	public function testAnUnauthoredTransitionReachesTheHandler(): void {
		$task = $this->taskFromTransition(['type' => 'createTask', 'title' => 'Beoordeel'], self::theCase());

		self::assertSame('alice', $task['assignee']);
	}//end testAnUnauthoredTransitionReachesTheHandler()

	/**
	 * The shipped template spelling lands on the same person on both paths.
	 *
	 * `{{ case.assignee }}` is what every declaration writes, and it resolving
	 * on one path and not the other is the drift this pair exists to catch.
	 *
	 * @return void
	 */
	public function testBothPathsRenderTheShippedSpellingTheSameWay(): void {
		$fromTransition = $this->taskFromTransition(
			['type' => 'createTask', 'title' => 'Beoordeel', 'assignee' => '{{ case.assignee }}'],
			self::theCase()
		);
		$fromFlow = $this->taskFromFlowStep(
			['question' => 'Beoordeel', 'assignee' => '{{ case.assignee }}'],
			self::theCase()
		);

		self::assertSame('alice', $fromTransition['assignee']);
		self::assertSame($fromTransition['assignee'], $fromFlow['assignee']);
	}//end testBothPathsRenderTheShippedSpellingTheSameWay()

	/**
	 * Neither path falls back to whoever triggered the work.
	 *
	 * The actor is "sander" on both, and a case with no handler must leave the
	 * task unassigned rather than put a passer-by's name on it. The transition
	 * creates the task and says so; the flow step refuses, which is its own
	 * long-standing rule and not the default's business.
	 *
	 * @return void
	 */
	public function testNeitherPathFallsBackToTheActor(): void {
		$case = ['id' => 'case-2', 'title' => 'Dakkapel'];

		$fromTransition = $this->taskFromTransition(
			['type' => 'createTask', 'title' => 'Beoordeel', 'assignee' => '{{ case.responsible }}'],
			$case
		);

		self::assertSame('', $fromTransition['assignee']);
		self::assertNotSame('sander', $fromTransition['assignee']);

		$this->expectException(\RuntimeException::class);
		$this->taskFromFlowStep(['question' => 'Beoordeel', 'assignee' => '{{ case.responsible }}'], $case);
	}//end testNeitherPathFallsBackToTheActor()
}//end class
