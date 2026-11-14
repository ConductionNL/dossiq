<?php

/**
 * The approval gate's verdict on the decidiq answers that keep an act closed.
 *
 * Pins the withdrawn, refused-to-read and gone answers, a case type named by
 * an embedded row, a case with no type at all, and the waiting list of a case
 * whose type could not be read.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Cases
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/besluitvorming-leaf/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Cases;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Cases\ApprovalGate;
use OCA\Dossiq\Service\CaseType\ApprovalGateDeclaration;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\ContractDecisionDelegationService;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * What the gate answers when decidiq does not say yes.
 *
 * @covers \OCA\Dossiq\Service\Cases\ApprovalGate
 * @uses   \OCA\Dossiq\Service\CaseType\ApprovalGateDeclaration
 *
 * @spec openspec/specs/besluitvorming-leaf/spec.md
 */
class ApprovalGateVerdictTest extends TestCase {

	/**
	 * The case type resolver double.
	 *
	 * @var CaseTypeResolver&MockObject
	 */
	private CaseTypeResolver&MockObject $caseTypes;

	/**
	 * The decidiq read seam double.
	 *
	 * @var ContractDecisionDelegationService&MockObject
	 */
	private ContractDecisionDelegationService&MockObject $decisions;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The gate under test.
	 *
	 * @var ApprovalGate
	 */
	private ApprovalGate $gate;

	/**
	 * Build the gate over doubles; the translator formats like Nextcloud's.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->caseTypes = $this->createMock(originalClassName: CaseTypeResolver::class);
		$this->decisions = $this->createMock(originalClassName: ContractDecisionDelegationService::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);

		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $params = []): string => vsprintf($text, $params)
		);

		$this->gate = new ApprovalGate(
			caseTypes: $this->caseTypes,
			decisions: $this->decisions,
			logger: $this->logger,
			l10n: $l10n,
		);
	}//end setUp()

	/**
	 * A withdrawn approval keeps the act closed as outstanding, naming the gate and the decision.
	 *
	 * @return void
	 */
	public function testAWithdrawnApprovalIsOutstanding(): void {
		$this->gatedCaseType();
		$this->decisions->expects(self::once())->method('readDecisionState')
			->with('dec-1', 'behandelaar')
			->willReturn(['state' => ContractDecisionDelegationService::DECISION_STATE_WITHDRAWN, 'envelope' => ['approvers' => ['Anna']]]);
		$this->logger->expects(self::never())->method('warning');

		$verdict = $this->gate->verdictFor(case: $this->linkedCase(), act: 'send-besluit', userId: 'behandelaar');

		self::assertSame(
			[
				'gated' => true,
				'allowed' => false,
				'rule' => ApprovalGate::RULE_OUTSTANDING,
				'sentence' => 'Teamleider approval was withdrawn without an answer. Ask for it again in decidiq.',
				'status' => RefusedException::STATUS_UNPROCESSABLE,
				'label' => 'Teamleider approval',
				'decisionRef' => 'dec-1',
				'approvers' => [],
			],
			$verdict
		);
	}//end testAWithdrawnApprovalIsOutstanding()

	/**
	 * An outcome the asker may not read keeps the act closed, indeterminate, and says who may.
	 *
	 * @return void
	 */
	public function testAnOutcomeTheAskerMayNotReadIsIndeterminate(): void {
		$this->gatedCaseType();
		$this->decisions->method('readDecisionState')
			->willReturn(['state' => ContractDecisionDelegationService::DECISION_STATE_REFUSED]);
		$this->logger->expects(self::once())->method('warning')->with(
			'Dossiq approval gate: the approval outcome could not be read, so the act stays refused',
			['decisionRef' => 'dec-1', 'state' => 'refused', 'act' => 'send-besluit'],
		);

		$verdict = $this->gate->verdictFor(case: $this->linkedCase(), act: 'send-besluit', userId: 'behandelaar');

		self::assertFalse($verdict['allowed']);
		self::assertSame(ApprovalGate::RULE_UNREADABLE, $verdict['rule']);
		self::assertSame(RefusedException::STATUS_INDETERMINATE, $verdict['status']);
		self::assertSame(
			'You may not read the outcome of Teamleider approval, so this act stays closed. Ask somebody who may.',
			$verdict['sentence']
		);
		self::assertSame('dec-1', $verdict['decisionRef']);
	}//end testAnOutcomeTheAskerMayNotReadIsIndeterminate()

	/**
	 * A decision decidiq no longer holds keeps the act closed and says to ask again.
	 *
	 * @return void
	 */
	public function testAGoneDecisionSaysToAskAgain(): void {
		$this->gatedCaseType();
		$this->decisions->method('readDecisionState')
			->willReturn(['state' => ContractDecisionDelegationService::DECISION_STATE_GONE]);
		$this->logger->expects(self::once())->method('warning');

		$verdict = $this->gate->verdictFor(case: $this->linkedCase(), act: 'send-besluit', userId: 'behandelaar');

		self::assertFalse($verdict['allowed']);
		self::assertSame(ApprovalGate::RULE_UNREADABLE, $verdict['rule']);
		self::assertSame(RefusedException::STATUS_INDETERMINATE, $verdict['status']);
		self::assertSame(
			'Teamleider approval no longer exists in decidiq. Ask for it again, then come back to this act.',
			$verdict['sentence']
		);
	}//end testAGoneDecisionSaysToAskAgain()

	/**
	 * A case type embedded as a row is resolved by its id, falling back to its uuid.
	 *
	 * @return void
	 */
	public function testAnEmbeddedCaseTypeIsResolvedByItsId(): void {
		$this->caseTypes->expects(self::exactly(2))->method('effectiveCaseType')
			->willReturnCallback(
				static function (string $caseTypeId): array {
					self::assertContains($caseTypeId, ['ct-1', 'ct-2']);

					return [];
				}
			);
		$this->decisions->expects(self::never())->method('readDecisionState');

		$byId = $this->gate->verdictFor(case: ['caseType' => ['id' => ' ct-1 ', 'uuid' => 'other']], act: 'send-besluit', userId: 'u');
		$byUuid = $this->gate->verdictFor(case: ['caseType' => ['uuid' => 'ct-2']], act: 'send-besluit', userId: 'u');

		self::assertFalse($byId['gated']);
		self::assertTrue($byId['allowed']);
		self::assertFalse($byUuid['gated']);
	}//end testAnEmbeddedCaseTypeIsResolvedByItsId()

	/**
	 * A case with no type declares no gates: the act is allowed and nothing is resolved.
	 *
	 * @return void
	 */
	public function testACaseWithoutATypeIsUngated(): void {
		$this->caseTypes->expects(self::never())->method('effectiveCaseType');

		$verdict = $this->gate->verdictFor(case: ['caseType' => '  '], act: 'send-besluit', userId: 'u');

		self::assertSame(
			[
				'gated' => false,
				'allowed' => true,
				'rule' => '',
				'sentence' => '',
				'status' => 0,
				'label' => '',
				'decisionRef' => '',
				'approvers' => [],
			],
			$verdict
		);
		self::assertSame([], $this->gate->awaiting(case: [], userId: 'u'));
	}//end testACaseWithoutATypeIsUngated()

	/**
	 * A case whose type cannot be read waits on nothing it can list, and the failure is logged.
	 *
	 * @return void
	 */
	public function testAwaitingIsEmptyWhenTheCaseTypeCannotBeRead(): void {
		$this->caseTypes->method('effectiveCaseType')->willThrowException(new RuntimeException('register down'));
		$this->decisions->expects(self::never())->method('readDecisionState');
		$this->logger->expects(self::once())->method('warning')->with(
			'Dossiq approval gate: the case type could not be read',
			['caseType' => 'ct-1', 'exception' => 'register down'],
		);

		self::assertSame([], $this->gate->awaiting(case: ['caseType' => 'ct-1'], userId: 'u'));
	}//end testAwaitingIsEmptyWhenTheCaseTypeCannotBeRead()

	/**
	 * The resolver answers a case type gating `send-besluit`.
	 *
	 * @return void
	 */
	private function gatedCaseType(): void {
		$this->caseTypes->method('effectiveCaseType')->willReturn([
			ApprovalGateDeclaration::DECLARATION => [
				['act' => 'send-besluit', 'decisionType' => 'besluit-approval', 'label' => 'Teamleider approval'],
			],
		]);
	}//end gatedCaseType()

	/**
	 * A case of that type whose `send-besluit` act is linked to decision `dec-1`.
	 *
	 * @return array<string, mixed> The case.
	 */
	private function linkedCase(): array {
		return [
			'id' => 'case-1',
			'caseType' => 'ct-1',
			ApprovalGateDeclaration::REFERENCES => [
				['act' => 'send-besluit', 'decisionRef' => 'dec-1', 'raisedAt' => '2026-11-01T10:00:00+01:00'],
			],
		];
	}//end linkedCase()
}//end class
