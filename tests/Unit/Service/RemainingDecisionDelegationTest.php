<?php

/**
 * Remaining Decision Delegation Unit Tests
 *
 * Covers the procest decision-delegation plumbing after the switch to the
 * decidesk IEventDispatcher contract: the shared raiseDecision core fails CLOSED
 * when the dispatched DecisionRequestedEvent is not handled (REQ-PDCD-002), and
 * the thin per-flow siblings (BezwaarDecisionDelegationService,
 * AdviceDelegationService) dispatch a Decision of the right decisionType and
 * return the decidesk decisionId when it is handled (REQ-PDCD-001).
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/procest-delegation-via-events/specs/contract-decision-delegation/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Decidesk\Event\DecisionRequestedEvent;
use OCA\Procest\Service\AdviceDelegationService;
use OCA\Procest\Service\BezwaarDecisionDelegationService;
use OCA\Procest\Service\ContractDecisionDelegationService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Procest\Service\ContractDecisionDelegationService
 * @covers \OCA\Procest\Service\BezwaarDecisionDelegationService
 * @covers \OCA\Procest\Service\AdviceDelegationService
 */
class RemainingDecisionDelegationTest extends TestCase {
	/**
	 * raiseDecision fails CLOSED when the dispatched event is not handled.
	 *
	 * @return void
	 */
	public function testRaiseDecisionFailsClosedWhenEventUnhandled(): void {
		// Dispatcher that does NOT handle the event (decidesk listener absent).
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped');

		$core = new ContractDecisionDelegationService(
			$dispatcher,
			$this->createMock(LoggerInterface::class),
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Decision service unavailable');

		$core->raiseDecision(
			decisionType: ContractDecisionDelegationService::DECISION_TYPE_ADVICE,
			externalReference: 'case-1',
			subject: ['subjectSchema' => 'adviesAanvraag', 'subjectId' => 'a-1'],
		);
	}//end testRaiseDecisionFailsClosedWhenEventUnhandled()

	/**
	 * raiseDecision returns the decidesk decisionId when the event is handled.
	 *
	 * @return void
	 */
	public function testRaiseDecisionReturnsRefWhenHandled(): void {
		$core = $this->coreThatHandlesWith(
			'decision-uuid-123',
			function (DecisionRequestedEvent $event): void {
				// The decisionType + sourceApp provenance must be carried.
				$this->assertSame('advice', $event->getDecisionType());
				$this->assertSame('procest', $event->getSourceApp());
			}
		);

		$ref = $core->raiseDecision(
			decisionType: ContractDecisionDelegationService::DECISION_TYPE_ADVICE,
			externalReference: 'case-1',
			subject: ['subjectSchema' => 'adviesAanvraag', 'subjectId' => 'a-1'],
		);

		$this->assertSame('decision-uuid-123', $ref);
	}//end testRaiseDecisionReturnsRefWhenHandled()

	/**
	 * The bezwaar sibling dispatches a bezwaar-decision Decision and returns the ref.
	 *
	 * @return void
	 */
	public function testBezwaarSiblingRaisesBezwaarDecision(): void {
		$core = $this->coreThatHandlesWith(
			'bd-1',
			function (DecisionRequestedEvent $event): void {
				$this->assertSame('bezwaar-decision', $event->getDecisionType());
				$this->assertSame('bezwaarDecision', $event->getSubjectSchema());
			}
		);

		$sibling = new BezwaarDecisionDelegationService($core);

		$ref = $sibling->raiseBezwaarDecision(
			'bezwaar-1',
			[
				'subjectId' => 'dec-1',
				'dispositionType' => 'dismissed',
				'reasoning' => 'r',
				'legalBasis' => 'Awb 7:11',
			]
		);

		$this->assertSame('bd-1', $ref);
	}//end testBezwaarSiblingRaisesBezwaarDecision()

	/**
	 * The advice sibling fails CLOSED when the event is not handled.
	 *
	 * @return void
	 */
	public function testAdviceSiblingFailsClosed(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped');

		$core = new ContractDecisionDelegationService(
			$dispatcher,
			$this->createMock(LoggerInterface::class),
		);
		$sibling = new AdviceDelegationService($core);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Decision service unavailable');

		$sibling->raiseAdviceDecision('adviesAanvraag', 'a-1', ['externalReference' => 'case-1']);
	}//end testAdviceSiblingFailsClosed()

	/**
	 * The voorstel besluit path dispatches a report-adoption Decision.
	 *
	 * @return void
	 */
	public function testVoorstelBesluitRaisesReportAdoption(): void {
		$core = $this->coreThatHandlesWith(
			'ra-1',
			function (DecisionRequestedEvent $event): void {
				$this->assertSame('report-adoption', $event->getDecisionType());
				$this->assertSame('proposal', $event->getSubjectSchema());
			}
		);

		$sibling = new AdviceDelegationService($core);

		$ref = $sibling->raiseVoorstelBesluit('voorstel-1', ['title' => 'Besluit X']);
		$this->assertSame('ra-1', $ref);
	}//end testVoorstelBesluitRaisesReportAdoption()

	/**
	 * Build a delegation core whose dispatcher simulates the decidesk listener
	 * marking the event handled and writing the given decisionId.
	 *
	 * @param string $decisionId The decisionId the simulated listener writes.
	 * @param callable|null $assert Optional assertion callback on the dispatched event.
	 *
	 * @return ContractDecisionDelegationService
	 */
	private function coreThatHandlesWith(string $decisionId, ?callable $assert = null): ContractDecisionDelegationService {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->once())
			->method('dispatchTyped')
			->willReturnCallback(
				function (Event $event) use ($decisionId, $assert): void {
					$this->assertInstanceOf(DecisionRequestedEvent::class, $event);
					if ($assert !== null) {
						$assert($event);
					}

					// Simulate the decidesk in-process listener writing the result.
					$event->setHandled(true);
					$event->setDecisionId($decisionId);
				}
			);

		return new ContractDecisionDelegationService(
			$dispatcher,
			$this->createMock(LoggerInterface::class),
		);
	}//end coreThatHandlesWith()
}//end class
