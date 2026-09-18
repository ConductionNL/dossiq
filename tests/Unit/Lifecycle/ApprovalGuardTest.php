<?php

/**
 * The gate between a case's acts and an approval decidiq is still walking.
 *
 * 🔴 THE FAILURE THIS FILE EXISTS FOR IS THE ONE THAT PASSES. A besluit going
 * out because decidiq was briefly unreachable looks, from inside dossiq,
 * exactly like a besluit going out because it was approved: same act, same
 * status move, same green log line. So the assertions that matter are not "an
 * approved case proceeds" but the four that must refuse, and every one of them
 * is driven over the REAL {@see ContractDecisionDelegationService} and the REAL
 * decidiq event class rather than a stubbed gate. A double of the delegation
 * service would answer whatever this file told it to and could not fail.
 *
 * 🔑 THE READ IS MADE TO FAIL THE WAY PRODUCTION FAILS. "Unreadable" is
 * produced by leaving the event unhandled, which is decidiq's own way of
 * saying it could not resolve the lookup, not by throwing a fabricated
 * exception at the gate.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Lifecycle;

use OCA\Decidiq\Event\DecisionStateRequestedEvent;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseType\ApprovalGateDeclaration;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\Cases\ApprovalGate;
use OCA\Dossiq\Service\ContractDecisionDelegationService;
use OCA\Dossiq\Service\Transitions\ApprovalGuard;
use OCA\Dossiq\Service\Transitions\ChecklistGuard;
use OCA\Dossiq\Service\Transitions\CapacityGuard;
use OCA\Dossiq\Service\Transitions\GuardRegistry;
use OCA\Dossiq\Service\Transitions\GuardResult;
use OCA\Dossiq\Service\Transitions\MandaatGuard;
use OCA\Dossiq\Service\Transitions\RequiredDocumentGuard;
use OCA\Dossiq\Service\Transitions\RequiredFieldGuard;
use OCA\Dossiq\Service\Transitions\RoleGuard;
use OCA\Dossiq\Service\Transitions\StatusChecklistGuard;
use OCA\Dossiq\Service\Transitions\TransitionSpecReader;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ApprovalGuardTest extends TestCase {

	/**
	 * The act the case type gates.
	 *
	 * @var string
	 */
	private const ACT = 'send-besluit';

	/**
	 * A case whose besluit waits on decidiq decision `dec-7f3c`.
	 *
	 * @return array<string, mixed> The case payload.
	 */
	private function caseWaiting(): array {
		return [
			'id' => 'case-1',
			'caseType' => 'ct-omgeving',
			ApprovalGateDeclaration::REFERENCES => [
				['act' => self::ACT, 'decisionRef' => 'dec-7f3c', 'raisedAt' => '2026-09-14T09:00:00+00:00'],
			],
		];
	}//end caseWaiting()

	/**
	 * A gate over a decidiq that answers one way, by a writer on the event.
	 *
	 * @param callable|null        $answer   What decidiq's listener writes back, or null for no listener.
	 * @param array<string, mixed> $caseType The case type the resolver answers with.
	 *
	 * @return ApprovalGate The gate under test.
	 */
	private function gateOver(?callable $answer, array $caseType = []): ApprovalGate {
		if ($caseType === []) {
			$caseType = [
				ApprovalGateDeclaration::DECLARATION => [
					[
						'act' => self::ACT,
						'decisionType' => 'besluit-approval',
						'label' => 'Approval by the teamleider',
					],
				],
			];
		}

		$resolver = $this->createMock(originalClassName: CaseTypeResolver::class);
		$resolver->method('effectiveCaseType')->willReturn($caseType);

		$dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (Event $event) use ($answer): void {
				if ($answer !== null && $event instanceof DecisionStateRequestedEvent) {
					$answer($event);
				}
			}
		);

		return new ApprovalGate(
			caseTypes: $resolver,
			decisions: new ContractDecisionDelegationService(
				eventDispatcher: $dispatcher,
				logger: new NullLogger(),
			),
			logger: new NullLogger(),
		);
	}//end gateOver()

	/**
	 * A listener that concludes the decision with the given status.
	 *
	 * @param string                   $status   decidiq's own status word.
	 * @param array<string, mixed>     $extra    Extra envelope keys.
	 *
	 * @return callable The listener.
	 */
	private function concludedAs(string $status, array $extra = []): callable {
		return static function (DecisionStateRequestedEvent $event) use ($status, $extra): void {
			$event->setHandled(true);
			$event->setPermitted(true);
			$event->setFound(true);
			$event->setEnvelope(array_merge(['status' => $status], $extra));
		};
	}//end concludedAs()

	/**
	 * The besluit is refused while the approval is open, with the approval named.
	 *
	 * @return void
	 */
	public function testAnOpenApprovalRefusesTheActAndNamesIt(): void {
		$gate = $this->gateOver(answer: $this->concludedAs(status: 'pending'));

		$verdict = $gate->verdictFor(case: $this->caseWaiting(), act: self::ACT, userId: 'behandelaar');

		self::assertFalse(condition: $verdict['allowed']);
		self::assertSame(expected: ApprovalGate::RULE_OUTSTANDING, actual: $verdict['rule']);
		self::assertStringContainsString(needle: 'Approval by the teamleider', haystack: $verdict['sentence']);
	}//end testAnOpenApprovalRefusesTheActAndNamesIt()

	/**
	 * The case names the people the approval waits on.
	 *
	 * @return void
	 */
	public function testTheCaseNamesWhoItIsWaitingOn(): void {
		$gate = $this->gateOver(
			answer: $this->concludedAs(
				status: 'pending',
				extra: ['pendingApprovers' => ['Sanne de Wit', ['name' => 'Joris Bakker']]],
			)
		);

		$waiting = $gate->awaiting(case: $this->caseWaiting(), userId: 'behandelaar');

		self::assertCount(expectedCount: 1, haystack: $waiting);
		self::assertSame(expected: ['Sanne de Wit', 'Joris Bakker'], actual: $waiting[0]['approvers']);
		self::assertStringContainsString(needle: 'Sanne de Wit', haystack: $waiting[0]['sentence']);
		self::assertStringContainsString(needle: 'Joris Bakker', haystack: $waiting[0]['sentence']);
		self::assertSame(expected: self::ACT, actual: $waiting[0]['act']);
	}//end testTheCaseNamesWhoItIsWaitingOn()

	/**
	 * Nobody named is said out loud, never rendered as waiting on nobody.
	 *
	 * @return void
	 */
	public function testAnApprovalWithNoNamedApproversSaysSo(): void {
		$gate = $this->gateOver(answer: $this->concludedAs(status: 'pending'));

		$verdict = $gate->verdictFor(case: $this->caseWaiting(), act: self::ACT, userId: 'behandelaar');

		self::assertSame(expected: [], actual: $verdict['approvers']);
		self::assertStringContainsString(needle: 'did not say who', haystack: $verdict['sentence']);
	}//end testAnApprovalWithNoNamedApproversSaysSo()

	/**
	 * An approval decidiq reports as granted lets the act through.
	 *
	 * @return void
	 */
	public function testAnApprovedDecisionLetsTheActThrough(): void {
		$gate = $this->gateOver(answer: $this->concludedAs(status: 'approved'));

		$verdict = $gate->verdictFor(case: $this->caseWaiting(), act: self::ACT, userId: 'behandelaar');

		self::assertTrue(condition: $verdict['allowed']);
		self::assertTrue(condition: $verdict['gated']);
		self::assertSame(expected: [], actual: $gate->awaiting(case: $this->caseWaiting(), userId: 'behandelaar'));
	}//end testAnApprovedDecisionLetsTheActThrough()

	/**
	 * A refused approval refuses the act, and says so rather than "still open".
	 *
	 * @return void
	 */
	public function testARejectedDecisionRefusesTheAct(): void {
		$gate = $this->gateOver(answer: $this->concludedAs(status: 'rejected'));

		$verdict = $gate->verdictFor(case: $this->caseWaiting(), act: self::ACT, userId: 'behandelaar');

		self::assertFalse(condition: $verdict['allowed']);
		self::assertSame(expected: ApprovalGate::RULE_REJECTED, actual: $verdict['rule']);
		self::assertSame(expected: RefusedException::STATUS_REFUSED, actual: $verdict['status']);
	}//end testARejectedDecisionRefusesTheAct()

	/**
	 * An outcome dossiq cannot read blocks, and says the service is unavailable.
	 *
	 * 🔴 THE ONE ASSERTION THIS CHANGE IS FOR (D-2, ADR-102). decidiq's
	 * listener leaves the event unhandled when it could not resolve the lookup,
	 * which is exactly what an absent or broken decidiq does in production.
	 * Passing here would mean a besluit goes out on an approval nobody read.
	 *
	 * @return void
	 */
	public function testAnUnreadableOutcomeBlocksRatherThanPasses(): void {
		$gate = $this->gateOver(answer: null);

		$verdict = $gate->verdictFor(case: $this->caseWaiting(), act: self::ACT, userId: 'behandelaar');

		self::assertFalse(condition: $verdict['allowed']);
		self::assertSame(expected: ApprovalGate::RULE_UNREADABLE, actual: $verdict['rule']);
		self::assertSame(expected: RefusedException::STATUS_INDETERMINATE, actual: $verdict['status']);
		self::assertStringContainsString(needle: 'approval service', haystack: $verdict['sentence']);
	}//end testAnUnreadableOutcomeBlocksRatherThanPasses()

	/**
	 * An approval that was never raised refuses the act too.
	 *
	 * A gated act with no reference recorded is not an ungated act. Reading it
	 * as one would let every gated act through until somebody remembered to
	 * start the walk.
	 *
	 * @return void
	 */
	public function testAGatedActWithNoApprovalRaisedIsRefused(): void {
		$gate = $this->gateOver(answer: $this->concludedAs(status: 'approved'));

		$verdict = $gate->verdictFor(
			case: ['id' => 'case-1', 'caseType' => 'ct-omgeving'],
			act: self::ACT,
			userId: 'behandelaar',
		);

		self::assertFalse(condition: $verdict['allowed']);
		self::assertSame(expected: ApprovalGate::RULE_OUTSTANDING, actual: $verdict['rule']);
		self::assertStringContainsString(needle: 'has not been asked for yet', haystack: $verdict['sentence']);
	}//end testAGatedActWithNoApprovalRaisedIsRefused()

	/**
	 * An act the case type does not gate is not touched, and asks decidiq nothing.
	 *
	 * @return void
	 */
	public function testAnUngatedActIsLetThroughWithoutAskingDecidiq(): void {
		$asked = 0;
		$gate = $this->gateOver(
			answer: static function (DecisionStateRequestedEvent $event) use (&$asked): void {
				$asked++;
			}
		);

		$verdict = $gate->verdictFor(case: $this->caseWaiting(), act: 'assign', userId: 'behandelaar');

		self::assertTrue(condition: $verdict['allowed']);
		self::assertFalse(condition: $verdict['gated']);
		self::assertSame(expected: 0, actual: $asked, message: 'An ungated act must not cost a read on decidiq.');
	}//end testAnUngatedActIsLetThroughWithoutAskingDecidiq()

	/**
	 * The write path refuses with the same verdict the menu drew.
	 *
	 * @return void
	 */
	public function testRequireApprovedThrowsTheVerdictAsARefusal(): void {
		$gate = $this->gateOver(answer: $this->concludedAs(status: 'pending'));

		try {
			$gate->requireApproved(case: $this->caseWaiting(), act: self::ACT, userId: 'behandelaar');
			self::fail(message: 'An act gated by an open approval must be refused on the write path too.');
		} catch (RefusedException $refusal) {
			self::assertSame(expected: ApprovalGate::RULE_OUTSTANDING, actual: $refusal->getRule());
			self::assertStringContainsString(needle: 'Approval by the teamleider', haystack: $refusal->getSentence());
			self::assertSame(expected: RefusedException::STATUS_UNPROCESSABLE, actual: $refusal->getStatus());
		}
	}//end testRequireApprovedThrowsTheVerdictAsARefusal()

	/**
	 * A registry with the approval evaluator wired, over a real checklist pass.
	 *
	 * @param ApprovalGuard|null $approvals The evaluator, or null for an old wiring.
	 *
	 * @return GuardRegistry The registry.
	 */
	private function registryWith(?ApprovalGuard $approvals): GuardRegistry {
		$checklist = $this->createMock(originalClassName: StatusChecklistGuard::class);
		$checklist->method('evaluate')->willReturn(new GuardResult(passed: true));

		$capacity = $this->createMock(originalClassName: CapacityGuard::class);
		$capacity->method('evaluate')->willReturn(new GuardResult(passed: true));

		return new GuardRegistry(
			checklist: $this->createMock(originalClassName: ChecklistGuard::class),
			requiredField: $this->createMock(originalClassName: RequiredFieldGuard::class),
			requiredDocument: $this->createMock(originalClassName: RequiredDocumentGuard::class),
			roleGuard: $this->createMock(originalClassName: RoleGuard::class),
			mandateGuard: $this->createMock(originalClassName: MandaatGuard::class),
			statusChecklist: $checklist,
			// `status-capacity-limit` (#2932) landed beside this change and made
			// the capacity guard a constructor argument. A double that PASSES
			// keeps this file measuring the approval gate rather than a limit no
			// status here declares. `GuardResult` is final, so the return value
			// has to be a real one rather than an auto-generated double.
			capacity: $capacity,
			logger: new NullLogger(),
			approvalGuard: $approvals,
		);
	}//end registryWith()

	/**
	 * Every transition carries the approval gate, declared or not, on BOTH doors.
	 *
	 * The offer and the move read one list. This drives that list through the
	 * real registry, so a gated transition that declares no guard of its own
	 * still comes back refused with the approval named, which is what the acts
	 * dialog and OpenRegister's lifecycle provider both render.
	 *
	 * @return void
	 */
	public function testAnUndeclaredTransitionIsStillGatedThroughTheEngine(): void {
		$registry = $this->registryWith(
			approvals: new ApprovalGuard(gate: $this->gateOver(answer: $this->concludedAs(status: 'pending')))
		);
		$transition = ['id' => self::ACT, 'label' => 'Verzend besluit', 'toStatus' => 'st-verzonden'];

		$guards = (new TransitionSpecReader())->guardsWithImplicit(
			transition: $transition,
			approvalsWired: $registry->knows(type: GuardRegistry::APPROVAL_GATE),
		);
		$eval = $registry->evaluateAll(guards: $guards, case: $this->caseWaiting(), userId: 'behandelaar');

		// `statusCapacity` sits between the two since #2932. The approval gate is
		// still LAST, which is the ordering this test is about: a role guard and
		// a checklist hide a transition before the gate has anything to say.
		self::assertSame(
			expected: ['statusChecklist', 'statusCapacity', 'approvalGate'],
			actual: array_column($eval, 'type'),
		);

		// Indexed by TYPE rather than by position from here on. The position was
		// 1 and is now 2, and the next implicit guard moves it again; what the
		// assertions are about is the approval gate's own answer.
		$byType = array_column($eval, null, 'type');
		self::assertFalse(condition: $registry->allPassed(results: $eval));
		self::assertStringContainsString(
			needle: 'Approval by the teamleider',
			haystack: (string)$byType['approvalGate']['failureMessage'],
		);
		self::assertSame(
			expected: ApprovalGate::RULE_OUTSTANDING,
			actual: $byType['approvalGate']['details']['rule'],
		);
	}//end testAnUndeclaredTransitionIsStillGatedThroughTheEngine()

	/**
	 * An approved transition passes the engine's gate.
	 *
	 * @return void
	 */
	public function testAnApprovedTransitionPassesTheEngine(): void {
		$registry = $this->registryWith(
			approvals: new ApprovalGuard(gate: $this->gateOver(answer: $this->concludedAs(status: 'approved')))
		);

		$eval = $registry->evaluateAll(
			guards: (new TransitionSpecReader())->guardsWithImplicit(
				transition: ['id' => self::ACT],
				approvalsWired: true,
			),
			case: $this->caseWaiting(),
			userId: 'behandelaar',
		);

		self::assertTrue(condition: $registry->allPassed(results: $eval));
	}//end testAnApprovedTransitionPassesTheEngine()

	/**
	 * A registry with no approval evaluator appends no approval guard.
	 *
	 * An implicit guard nobody answers for is refused as unknown, on every
	 * transition of every case. That is a broken engine, not a closed gate.
	 *
	 * @return void
	 */
	public function testAnUnwiredRegistryAppendsNoApprovalGuard(): void {
		$registry = $this->registryWith(approvals: null);

		self::assertFalse(condition: $registry->knows(type: GuardRegistry::APPROVAL_GATE));
		// The capacity guard is unconditional and is listed here for that
		// reason: it arrived with `status-capacity-limit` (#2932) and, unlike the
		// approval gate, it is not wired behind a registry entry. The assertion
		// stays an identity rather than a `contains`, because what it is about
		// is the approval guard being ABSENT, and a containment check cannot
		// tell an absence from a list that grew.
		self::assertSame(
			expected: [
				['type' => GuardRegistry::STATUS_CHECKLIST],
				['type' => GuardRegistry::STATUS_CAPACITY, 'toStatus' => ''],
			],
			actual: (new TransitionSpecReader())->guardsWithImplicit(
				transition: ['id' => self::ACT],
				approvalsWired: false,
			),
		);
	}//end testAnUnwiredRegistryAppendsNoApprovalGuard()

	/**
	 * A case type nobody could read refuses rather than declaring no gates.
	 *
	 * @return void
	 */
	public function testAnUnreadableCaseTypeRefusesRatherThanAllowing(): void {
		$resolver = $this->createMock(originalClassName: CaseTypeResolver::class);
		$resolver->method('effectiveCaseType')->willThrowException(new \RuntimeException('register down'));

		$gate = new ApprovalGate(
			caseTypes: $resolver,
			decisions: new ContractDecisionDelegationService(
				eventDispatcher: $this->createMock(originalClassName: IEventDispatcher::class),
				logger: new NullLogger(),
			),
			logger: new NullLogger(),
		);

		$verdict = $gate->verdictFor(case: $this->caseWaiting(), act: self::ACT, userId: 'behandelaar');

		self::assertFalse(condition: $verdict['allowed']);
		self::assertSame(expected: RefusedException::STATUS_INDETERMINATE, actual: $verdict['status']);
	}//end testAnUnreadableCaseTypeRefusesRatherThanAllowing()
}//end class
