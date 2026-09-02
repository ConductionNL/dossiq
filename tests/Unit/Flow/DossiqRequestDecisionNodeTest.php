<?php

/**
 * Unit tests for DossiqRequestDecisionNode — ask decidiq, then wait.
 *
 * 🔴 THE FAIL-CLOSED TEST IS THE POINT. When decidiq is unavailable the step
 * must FAIL and the run must stop at the decision. The tempting alternative —
 * catch, log, carry on — produces a case decided by nobody, which is the single
 * outcome a decision step exists to prevent.
 *
 * The second property is idempotence: a heartbeat must not raise the decision
 * again, or people are convened repeatedly for a question already asked.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Flow;

use OCA\Dossiq\Flow\DossiqRequestDecisionNode;
use OCA\Dossiq\Service\ContractDecisionDelegationService;
use OCA\Dossiq\Service\FlowRunAsScope;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use UnexpectedValueException;

class DossiqRequestDecisionNodeTest extends TestCase {

	/**
	 * How many decisions the delegation was asked to raise.
	 *
	 * @var integer
	 */
	private int $raised = 0;

	/**
	 * The uids the object service's runAs seam was asked to act as.
	 *
	 * @var string[]
	 */
	private array $actedAs = [];

	/**
	 * Whether the runAs seam's callable is currently executing.
	 *
	 * @var boolean
	 */
	private bool $insideRunAs = false;

	/**
	 * Whether the raise happened INSIDE the runAs seam's callable.
	 *
	 * @var boolean
	 */
	private bool $raisedInsideRunAs = false;

	protected function setUp(): void {
		$this->raised = 0;
		$this->actedAs = [];
		$this->insideRunAs = false;
		$this->raisedInsideRunAs = false;
	}//end setUp()

	/**
	 * The node, wired to a delegation that behaves as told.
	 *
	 * @param string|null $ref   The ref to return, or null to throw (decidiq down).
	 *
	 * @return DossiqRequestDecisionNode The node under test.
	 */
	private function node(?string $ref = 'decision-1'): DossiqRequestDecisionNode {
		$delegation = $this->createMock(ContractDecisionDelegationService::class);

		if ($ref === null) {
			$delegation->method('raiseDecision')->willReturnCallback(
				function (): string {
					$this->raised++;

					throw new RuntimeException('decidiq unavailable');
				}
			);
		} else {
			$delegation->method('raiseDecision')->willReturnCallback(
				function () use ($ref): string {
					$this->raised++;
					$this->raisedInsideRunAs = $this->insideRunAs;

					return $ref;
				}
			);
		}

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new DossiqRequestDecisionNode($delegation, $this->scope(), $l10n, new NullLogger());
	}//end node()

	/**
	 * A runAs scope over an object service that records the seam being used.
	 *
	 * @return FlowRunAsScope The scope.
	 */
	private function scope(): FlowRunAsScope {
		$test = $this;
		$objectService = new class($this->actedAs, $test) {
			public function __construct(private array &$actedAs, private DossiqRequestDecisionNodeTest $test) {
			}

			public function runAs(IUser $user, callable $operation): mixed {
				$this->actedAs[] = $user->getUID();
				$this->test->markInsideRunAs(true);

				try {
					return $operation();
				} finally {
					$this->test->markInsideRunAs(false);
				}
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);

		$adminUser = $this->createMock(IUser::class);
		$adminUser->method('getUID')->willReturn('admin');
		$adminUser->method('isEnabled')->willReturn(true);

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(
			static fn (string $uid): ?IUser => ($uid === 'admin') ? $adminUser : null
		);

		return new FlowRunAsScope($settings, $users);
	}//end scope()

	/**
	 * Toggle the inside-runAs marker (called by the anonymous seam fake).
	 *
	 * @param bool $inside Whether the seam's callable is executing.
	 *
	 * @return void
	 */
	public function markInsideRunAs(bool $inside): void {
		$this->insideRunAs = $inside;
	}//end markInsideRunAs()

	/**
	 * One item carrying a case.
	 *
	 * @return array<int, array<string, mixed>> The items.
	 */
	private function items(): array {
		return [['json' => ['id' => 'case-1', 'title' => 'Dakkapel']]];
	}//end items()

	/**
	 * A valid configuration.
	 *
	 * @return array<string, mixed> The config.
	 */
	private function config(): array {
		return ['question' => 'Toets aan register B'];
	}//end config()

	public function testItRaisesTheDecisionAndSuspends(): void {
		$resume = new FlowNodeResumeState('decide-register-b');

		try {
			$this->node()->execute($this->items(), $this->config(), [FlowNodeResumeState::CONTEXT_KEY => $resume]);
			self::fail('The node must suspend while the decision is outstanding.');
		} catch (FlowSuspension $suspension) {
			self::assertStringContainsString('Toets aan register B', $suspension->getMessage());
		}

		self::assertSame(1, $this->raised);
		self::assertSame('decision-1', $resume->get('decisionRef'));
	}//end testItRaisesTheDecisionAndSuspends()

	/**
	 * 🔴 THE RAISE RUNS AS THE RUN'S ACTING IDENTITY.
	 *
	 * The delegation dispatches decidiq's DecisionRequestedEvent, whose
	 * listener writes the decision object synchronously under the AMBIENT
	 * SESSION user — under FlowRunWorker, nobody. Measured live: the raise
	 * was refused as 'Anonymous' while the run context carried `runAs: admin`.
	 * The whole dispatch must therefore execute INSIDE the object service's
	 * runAs seam. Remove the wrap and this goes red twice over: $actedAs
	 * stays empty and the raise records itself as outside the seam.
	 */
	public function testTheDecisionIsRaisedAsTheRunsActingIdentity(): void {
		$resume = new FlowNodeResumeState('decide-register-b');

		try {
			$this->node()->execute(
				$this->items(),
				$this->config(),
				[
					FlowNodeResumeState::CONTEXT_KEY => $resume,
					'runAs' => 'admin',
				]
			);
		} catch (FlowSuspension $e) {
			// expected: the decision is outstanding
		}

		self::assertSame(1, $this->raised);
		self::assertSame(['admin'], $this->actedAs, 'The raise must go through the object service\'s runAs seam.');
		self::assertTrue($this->raisedInsideRunAs, 'And the dispatch itself must happen INSIDE that scope, not next to it.');
	}//end testTheDecisionIsRaisedAsTheRunsActingIdentity()

	/**
	 * An acting identity that resolves to nobody refuses the step: falling
	 * back to the ambient session would recreate the anonymous raise.
	 */
	public function testAnUnresolvableActingIdentityRefuses(): void {
		$resume = new FlowNodeResumeState('decide-register-b');

		$this->expectException(RuntimeException::class);

		try {
			$this->node()->execute(
				$this->items(),
				$this->config(),
				[
					FlowNodeResumeState::CONTEXT_KEY => $resume,
					'runAs' => 'ghost',
				]
			);
		} finally {
			self::assertSame(0, $this->raised, 'Nothing may be raised under an identity that does not resolve.');
		}
	}//end testAnUnresolvableActingIdentityRefuses()

	/**
	 * 🔴 A heartbeat must not raise a SECOND decision.
	 */
	public function testAHeartbeatDoesNotRaiseTheDecisionAgain(): void {
		$resume = new FlowNodeResumeState('decide-register-b');
		$node = $this->node();

		foreach ([1, 2, 3] as $ignored) {
			try {
				$node->execute($this->items(), $this->config(), [FlowNodeResumeState::CONTEXT_KEY => $resume]);
			} catch (FlowSuspension $e) {
				// expected while unanswered
			}
		}

		self::assertSame(1, $this->raised, 'One question asked once, however many times the run wakes.');
	}//end testAHeartbeatDoesNotRaiseTheDecisionAgain()

	/**
	 * 🔴 FAIL CLOSED. decidiq down means the run STOPS, not proceeds.
	 *
	 * Asserted as "not a FlowSuspension": suspending would leave the run
	 * waiting for a decision that was never actually raised, which looks like
	 * patience and is really a case that can never advance.
	 */
	public function testAnUnavailableDecisionServiceFailsTheStep(): void {
		$resume = new FlowNodeResumeState('decide-register-b');

		try {
			$this->node(ref: null)->execute(
				$this->items(),
				$this->config(),
				[FlowNodeResumeState::CONTEXT_KEY => $resume]
			);
			self::fail('The step must fail when the decision cannot be raised.');
		} catch (FlowSuspension $e) {
			self::fail('A failure to raise must NOT read as waiting.');
		} catch (RuntimeException $e) {
			self::assertStringContainsString('decision_could_not_be_raised', $e->getMessage());
		}
	}//end testAnUnavailableDecisionServiceFailsTheStep()

	/**
	 * A raise that returns no reference cannot be correlated later.
	 */
	public function testARaiseWithoutAReferenceFails(): void {
		$this->expectException(RuntimeException::class);

		$this->node(ref: '  ')->execute(
			$this->items(),
			$this->config(),
			[FlowNodeResumeState::CONTEXT_KEY => new FlowNodeResumeState('n')]
		);
	}//end testARaiseWithoutAReferenceFails()

	public function testTheOutcomeIsCarriedOntoTheItems(): void {
		$resume = new FlowNodeResumeState('decide-register-b', ['decisionRef' => 'decision-1', 'askedAt' => 'now']);

		$out = $this->node()->execute(
			$this->items(),
			array_merge($this->config(), ['signalKey' => 'toets']),
			[
				FlowNodeResumeState::CONTEXT_KEY => $resume,
				'signal' => ['decision' => 'approved', 'decisionRef' => 'decision-1'],
			]
		);

		self::assertSame('approved', $out[0]['json']['toets']['decision']);
		self::assertSame(0, $this->raised, 'An arriving outcome must not raise anything.');
	}//end testTheOutcomeIsCarriedOntoTheItems()

	public function testAConfigWithNoQuestionIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);

		$this->node()->validateConfig([]);
	}//end testAConfigWithNoQuestionIsRefused()

	public function testWithoutAResumeSlotItRefuses(): void {
		$this->expectException(RuntimeException::class);

		$this->node()->execute($this->items(), $this->config(), []);
	}//end testWithoutAResumeSlotItRefuses()

	public function testItAnnouncesItsIdentity(): void {
		$node = $this->node();

		self::assertSame('dossiq.requestDecision', $node->getId());
		self::assertNotSame('', $node->getDisplayName());
	}//end testItAnnouncesItsIdentity()
}//end class
