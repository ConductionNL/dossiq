<?php

/**
 * AdviceTransitionGuards tests: the panel check before deliberation and the advice decision before advice is issued.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Bezwaar
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
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Bezwaar;

use OCA\Dossiq\Service\AdviceDelegationService;
use OCA\Dossiq\Service\Bezwaar\AdviceTransitionGuards;
use OCA\Dossiq\Service\Bezwaar\BezwaarAuditTrail;
use OCA\Dossiq\Service\Bezwaar\PanelIndependenceChecker;
use OCA\Dossiq\Service\Transitions\GuardFailedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The guards of an advice request's moves.
 *
 * @covers \OCA\Dossiq\Service\Bezwaar\AdviceTransitionGuards
 * @uses   \OCA\Dossiq\Service\Bezwaar\ReadsPanelMembers
 */
class AdviceTransitionGuardsTest extends TestCase {

	/**
	 * @var AdviceDelegationService&MockObject
	 */
	private AdviceDelegationService&MockObject $delegation;

	/**
	 * @var BezwaarAuditTrail&MockObject
	 */
	private BezwaarAuditTrail&MockObject $auditTrail;

	/**
	 * @var PanelIndependenceChecker&MockObject
	 */
	private PanelIndependenceChecker&MockObject $independence;

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->delegation   = $this->createMock(AdviceDelegationService::class);
		$this->auditTrail   = $this->createMock(BezwaarAuditTrail::class);
		$this->independence = $this->createMock(PanelIndependenceChecker::class);
	}//end setUp()

	/**
	 * The guards under test.
	 *
	 * @return AdviceTransitionGuards
	 */
	private function guards(): AdviceTransitionGuards {
		return new AdviceTransitionGuards(
			logger: $this->createMock(LoggerInterface::class),
			adviceDelegation: $this->delegation,
			auditTrail: $this->auditTrail,
			independence: $this->independence,
		);
	}//end guards()

	/**
	 * A stored request with the store it lives in.
	 *
	 * @param array<string, mixed> $current The stored request.
	 *
	 * @return array<string, mixed>
	 */
	private function request(array $current): array {
		return ['objectService' => new \stdClass(), 'register' => 'reg', 'schema' => 'adviceRequest', 'id' => 'req-1', 'current' => $current];
	}//end request()

	/**
	 * A move that needs no guard checks nothing and raises no decision.
	 *
	 * @return void
	 */
	public function testAMoveWithoutAGuardPasses(): void {
		$this->independence->expects($this->never())->method('check');
		$this->delegation->expects($this->never())->method('raiseAdviceDecision');

		$ref = $this->guards()->guardMove(
			request: $this->request(current: ['panel' => []]),
			move: ['from' => 'in-deliberation', 'status' => 'inadmissible']
		);

		$this->assertSame('', $ref);
	}//end testAMoveWithoutAGuardPasses()

	/**
	 * Starting deliberation checks the trimmed panel against the objection.
	 *
	 * @return void
	 */
	public function testAnIndependentPanelStartsDeliberation(): void {
		$this->independence->expects($this->once())->method('check')
			->with('bz-1', ['alice', 'bob'])
			->willReturn(['ok' => true, 'member' => null, 'reason' => '']);
		$this->auditTrail->expects($this->never())->method('recordRefusal');

		$ref = $this->guards()->guardMove(
			request: $this->request(current: ['bezwaar' => 'bz-1', 'panel' => [' alice ', 'bob', '']]),
			move: ['from' => 'assigned', 'status' => 'in-deliberation']
		);

		$this->assertSame('', $ref);
	}//end testAnIndependentPanelStartsDeliberation()

	/**
	 * A conflicting member is recorded on the trail, then refused.
	 *
	 * @return void
	 */
	public function testAConflictingMemberIsRecordedAndRefused(): void {
		$this->independence->method('check')->willReturn(['ok' => false, 'member' => 'bob', 'reason' => 'party']);
		$this->auditTrail->expects($this->once())->method('recordRefusal')->with(
			'reg',
			'adviceRequest',
			'req-1',
			'independence-check-failed',
			['conflictingMember' => 'bob', 'reason' => 'party'],
		);

		$this->expectException(GuardFailedException::class);
		$this->expectExceptionMessage('Panel member conflict (Awb Art. 7:13 lid 3): party');

		$this->guards()->guardPanel(request: $this->request(current: ['objectionProceeding' => 'bz-1', 'panel' => ['bob']]));
	}//end testAConflictingMemberIsRecordedAndRefused()

	/**
	 * Independence that cannot be verified is its own refusal.
	 *
	 * @return void
	 */
	public function testUnverifiableIndependenceIsRefused(): void {
		$this->independence->method('check')->willReturn(['ok' => false, 'member' => null, 'reason' => 'no lookup']);
		$this->auditTrail->expects($this->once())->method('recordRefusal')
			->with($this->anything(), $this->anything(), $this->anything(), 'independence-check-unverifiable', $this->anything());

		$this->expectException(GuardFailedException::class);
		$this->expectExceptionMessage('Panel independence could not be verified (Awb Art. 7:13 lid 3): no lookup');

		$this->guards()->guardPanel(request: $this->request(current: ['bezwaar' => 'bz-1', 'panel' => ['bob']]));
	}//end testUnverifiableIndependenceIsRefused()

	/**
	 * Issuing advice raises the decision, referenced to the objection, with the patch over the stored request.
	 *
	 * @return void
	 */
	public function testIssuingAdviceRaisesTheDecision(): void {
		$this->delegation->expects($this->once())->method('raiseAdviceDecision')->with(
			'bacAdviceRequest',
			'req-1',
			[
				'subjectRegister' => 'reg',
				'externalReference' => 'bz-1',
				'subjectLabel' => 'Gegrond',
				'adviceType' => 'herroepen',
				'question' => 'Gegrond',
			],
		)->willReturn('dec-9');

		$ref = $this->guards()->guardMove(
			request: $this->request(current: ['bezwaar' => 'bz-1', 'recommendation' => 'herroepen']),
			move: ['from' => 'in-deliberation', 'status' => 'advice-issued', 'conclusion' => 'Gegrond']
		);

		$this->assertSame('dec-9', $ref);
	}//end testIssuingAdviceRaisesTheDecision()

	/**
	 * Without an objection the decision is referenced to the request itself.
	 *
	 * @return void
	 */
	public function testTheDecisionFallsBackToTheRequestId(): void {
		$this->delegation->expects($this->once())->method('raiseAdviceDecision')
			->with($this->anything(), $this->anything(), $this->callback(static fn (array $p): bool => $p['externalReference'] === 'req-1'))
			->willReturn('dec-1');

		$this->guards()->guardMove(
			request: $this->request(current: ['conclusion' => 'a', 'recommendation' => 'b']),
			move: ['from' => 'in-deliberation', 'status' => 'advice-issued']
		);
	}//end testTheDecisionFallsBackToTheRequestId()

	/**
	 * Advice without a conclusion is refused before any decision is raised.
	 *
	 * @return void
	 */
	public function testAdviceWithoutAConclusionIsRefused(): void {
		$this->delegation->expects($this->never())->method('raiseAdviceDecision');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Advice cannot be issued: missing required field conclusion');

		$this->guards()->guardMove(
			request: $this->request(current: ['recommendation' => 'b']),
			move: ['from' => 'in-deliberation', 'status' => 'advice-issued', 'conclusion' => '']
		);
	}//end testAdviceWithoutAConclusionIsRefused()

	/**
	 * A failing decision service fails closed.
	 *
	 * @return void
	 */
	public function testAFailingDecisionServiceFailsClosed(): void {
		$this->delegation->method('raiseAdviceDecision')->willThrowException(new RuntimeException('down'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Decision service unavailable: down');

		$this->guards()->guardMove(
			request: $this->request(current: ['conclusion' => 'a', 'recommendation' => 'b']),
			move: ['from' => 'in-deliberation', 'status' => 'advice-issued']
		);
	}//end testAFailingDecisionServiceFailsClosed()
}//end class
