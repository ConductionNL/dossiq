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
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCP\IL10N;
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

	protected function setUp(): void {
		$this->raised = 0;
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

					return $ref;
				}
			);
		}

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new DossiqRequestDecisionNode($delegation, $l10n, new NullLogger());
	}//end node()

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

	/**
	 * One node's resume slot, built the way the ENGINE builds it.
	 *
	 * A `FlowNodeResumeState` is not constructible on its own: it is a scoped
	 * VIEW onto the run-level `FlowResumeState`, and its real constructor takes
	 * that parent plus the node id. Tests here used to call
	 * `new FlowNodeResumeState('ask-indiener', [...])` — a two-argument shape
	 * the real class has never had — so 21 of them fataled against a real
	 * OpenRegister while passing against the stub.
	 *
	 * @param string               $nodeId The node the slot belongs to.
	 * @param array<string, mixed> $values What the slot already holds.
	 *
	 * @return FlowNodeResumeState The scoped handle the engine would hand the node.
	 */
	private static function resumeSlot(string $nodeId, array $values = []): FlowNodeResumeState {
		$slots = [];
		if ($values !== []) {
			$slots[$nodeId] = $values;
		}

		return (new FlowResumeState($slots))->forNode($nodeId);
	}//end resumeSlot()

	public function testItRaisesTheDecisionAndSuspends(): void {
		$resume = self::resumeSlot('decide-register-b');

		try {
			$this->node()->execute($this->items(), $this->config(), [FlowNodeResumeState::CONTEXT_KEY => $resume]);
			self::fail('The node must suspend while the decision is outstanding.');
		} catch (FlowSuspension $suspension) {
			self::assertStringContainsString('Toets aan register B', $suspension->getMessage());
		}

		self::assertSame(1, $this->raised);
		self::assertSame('decision-1', $resume->get('decisionRef'));
	}//end testItRaisesTheDecisionAndSuspends()

	// The runAs tests retired with dossiq's FlowRunAsScope: the engine's
	// RegistryStepDispatcher executes every contributed node inside
	// ObjectService::runAs() as the run's validated acting identity
	// (openregister#3332, proven by its RegistryStepDispatcherRunAsTest), so
	// the whole dispatch — including decidiq's synchronous listener write — is
	// scoped without a local wrap, and a test demanding one would re-encode
	// the retired requirement.

	/**
	 * 🔴 A heartbeat must not raise a SECOND decision.
	 */
	public function testAHeartbeatDoesNotRaiseTheDecisionAgain(): void {
		$resume = self::resumeSlot('decide-register-b');
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
		$resume = self::resumeSlot('decide-register-b');

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
			[FlowNodeResumeState::CONTEXT_KEY => self::resumeSlot('n')]
		);
	}//end testARaiseWithoutAReferenceFails()

	public function testTheOutcomeIsCarriedOntoTheItems(): void {
		$resume = self::resumeSlot('decide-register-b', ['decisionRef' => 'decision-1', 'askedAt' => 'now']);

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
