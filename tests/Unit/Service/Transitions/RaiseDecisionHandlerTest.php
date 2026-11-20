<?php

/**
 * Unit tests for RaiseDecisionHandler: a transition hands the case to the
 * decision app as a decision of the configured type, and a missing case,
 * a missing type or a failed hand-off comes back as a named error.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\ContractDecisionDelegationService;
use OCA\Dossiq\Service\Transitions\RaiseDecisionHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Transitions\RaiseDecisionHandler
 *
 * @uses \OCA\Dossiq\Service\Transitions\ActionResult
 *
 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md
 */
class RaiseDecisionHandlerTest extends TestCase {

	private ContractDecisionDelegationService&MockObject $decisions;

	private LoggerInterface&MockObject $logger;

	private RaiseDecisionHandler $handler;

	protected function setUp(): void {
		parent::setUp();
		$this->decisions = $this->createMock(ContractDecisionDelegationService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->handler = new RaiseDecisionHandler(decisions: $this->decisions, logger: $this->logger);
	}//end setUp()

	public function testTheCaseIsRaisedAsADecisionOfTheConfiguredType(): void {
		$this->decisions->expects($this->once())->method('raiseDecision')->with(
			'tender-award',
			'case-1',
			[
				'subjectRegister' => 'dossiq',
				'subjectSchema' => 'case',
				'subjectId' => 'case-1',
				'subjectLabel' => 'Aanbesteding jeugdzorg 2026',
			],
			['actorId' => 'inkoper-a', 'transition' => 'Definitief gunnen']
		)->willReturn('decision-9');
		$this->logger->expects($this->never())->method('error');

		$result = $this->handler->handle(
			['type' => 'raiseDecision', 'decisionType' => ' tender-award '],
			['id' => ' case-1 ', 'title' => 'Aanbesteding jeugdzorg 2026'],
			['userId' => 'inkoper-a', 'transitionLabel' => 'Definitief gunnen']
		);

		$this->assertTrue($result->succeeded);
		$this->assertNull($result->error);
		$this->assertSame(['decisionRef' => 'decision-9', 'decisionType' => 'tender-award'], $result->data);
	}//end testTheCaseIsRaisedAsADecisionOfTheConfiguredType()

	public function testTheUuidIsUsedWhenNoIdIsSet(): void {
		$this->decisions->expects($this->once())->method('raiseDecision')
			->with('tender-award', 'uuid-2', $this->anything(), ['actorId' => '', 'transition' => ''])
			->willReturn('decision-3');

		$result = $this->handler->handle(['decisionType' => 'tender-award'], ['uuid' => 'uuid-2'], []);

		$this->assertTrue($result->succeeded);
		$this->assertSame('decision-3', $result->data['decisionRef']);
	}//end testTheUuidIsUsedWhenNoIdIsSet()

	public function testACaseWithoutAnIdRaisesNothing(): void {
		$this->decisions->expects($this->never())->method('raiseDecision');

		$result = $this->handler->handle(['decisionType' => 'tender-award'], ['id' => '  '], []);

		$this->assertFalse($result->succeeded);
		$this->assertSame('case_not_found', $result->error);
	}//end testACaseWithoutAnIdRaisesNothing()

	public function testAnActionWithoutADecisionTypeRaisesNothing(): void {
		$this->decisions->expects($this->never())->method('raiseDecision');

		$result = $this->handler->handle(['type' => 'raiseDecision', 'decisionType' => ' '], ['id' => 'case-1'], []);

		$this->assertFalse($result->succeeded);
		$this->assertSame('decision_type_missing', $result->error);
	}//end testAnActionWithoutADecisionTypeRaisesNothing()

	public function testAFailedHandOffIsLoggedAndNamedButDoesNotThrow(): void {
		$this->decisions->method('raiseDecision')->willThrowException(new RuntimeException('the decision app is not installed'));
		$this->logger->expects($this->once())->method('error')->with(
			'RaiseDecisionHandler: the decision could not be raised',
			['exception' => 'the decision app is not installed', 'case' => 'case-1', 'decisionType' => 'tender-award']
		);

		$result = $this->handler->handle(['decisionType' => 'tender-award'], ['id' => 'case-1'], []);

		$this->assertFalse($result->succeeded);
		$this->assertSame('raise_decision_failed', $result->error);
		$this->assertSame([], $result->data);
	}//end testAFailedHandOffIsLoggedAndNamedButDoesNotThrow()
}//end class
