<?php

/**
 * The approval gate's verdict on a decided, an open and an unasked approval.
 *
 * Pins the one answer that opens an act (decided and approved), a refusal on
 * any other decided status, an open approval naming who it waits on (read
 * from whichever approver key decidiq filled first), an act whose approval
 * was never asked for, an ungated act, an unreadable case type and an
 * unreadable decidiq answer, and the waiting list over every declared gate.
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
 * What the gate answers when decidiq has an answer, or was never asked.
 *
 * @covers \OCA\Dossiq\Service\Cases\ApprovalGate
 * @uses   \OCA\Dossiq\Service\CaseType\ApprovalGateDeclaration
 * @uses   \OCA\Dossiq\Service\Cases\DecidiqAnswer
 * @uses   \OCA\Dossiq\Service\Support\RowList
 *
 * @spec openspec/specs/besluitvorming-leaf/spec.md
 */
class ApprovalGateDecisionTest extends TestCase {

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
	 * A decided, approved answer opens the act and carries the gate, decision and approvers.
	 *
	 * @return void
	 */
	public function testAnApprovedDecisionOpensTheAct(): void {
		$this->gatedCaseType();
		$this->decisions->expects(self::once())->method('readDecisionState')
			->with('dec-1', 'behandelaar')
			->willReturn([
				'state' => ContractDecisionDelegationService::DECISION_STATE_DECIDED,
				'status' => 'approved',
				'envelope' => ['approvers' => ['Anna', ' Anna ', 'Bram']],
			]);

		$verdict = $this->gate->verdictFor(case: $this->linkedCase(), act: 'send-besluit', userId: 'behandelaar');

		self::assertSame(
			[
				'gated' => true,
				'allowed' => true,
				'rule' => '',
				'sentence' => '',
				'status' => 0,
				'label' => 'Teamleider approval',
				'decisionRef' => 'dec-1',
				'approvers' => ['Anna', 'Bram'],
			],
			$verdict
		);
	}//end testAnApprovedDecisionOpensTheAct()

	/**
	 * Any other decided status is a refusal under the rejected rule.
	 *
	 * @return void
	 */
	public function testARejectedDecisionKeepsTheActClosed(): void {
		$this->gatedCaseType();
		$this->decisions->method('readDecisionState')->willReturn([
			'state' => ContractDecisionDelegationService::DECISION_STATE_DECIDED,
			'status' => 'rejected',
			'envelope' => [],
		]);

		$verdict = $this->gate->verdictFor(case: $this->linkedCase(), act: 'send-besluit', userId: 'behandelaar');

		self::assertFalse($verdict['allowed']);
		self::assertSame(ApprovalGate::RULE_REJECTED, $verdict['rule']);
		self::assertSame(RefusedException::STATUS_REFUSED, $verdict['status']);
		self::assertSame('Teamleider approval was refused. This act stays closed while that stands.', $verdict['sentence']);
		self::assertSame('dec-1', $verdict['decisionRef']);
	}//end testARejectedDecisionKeepsTheActClosed()

	/**
	 * An open approval names its approvers from the first key decidiq filled, as names, display names or user ids.
	 *
	 * @return void
	 */
	public function testAnOpenApprovalSaysWhoItWaitsOn(): void {
		$this->gatedCaseType();
		$this->decisions->method('readDecisionState')->willReturn([
			'state' => ContractDecisionDelegationService::DECISION_STATE_OPEN,
			'envelope' => [
				'pendingApprovers' => 'not a list',
				'outstandingApprovers' => [['name' => 'Anna'], ['displayName' => 'Bram'], ['userId' => 'cees'], ['other' => 'x'], 7],
				'approvers' => ['Dirk'],
			],
		]);

		$verdict = $this->gate->verdictFor(case: $this->linkedCase(), act: 'send-besluit', userId: 'behandelaar');

		self::assertFalse($verdict['allowed']);
		self::assertSame(ApprovalGate::RULE_OUTSTANDING, $verdict['rule']);
		self::assertSame(RefusedException::STATUS_UNPROCESSABLE, $verdict['status']);
		self::assertSame(['Anna', 'Bram', 'cees'], $verdict['approvers']);
		self::assertSame('Teamleider approval is still open. It is waiting on Anna, Bram, cees.', $verdict['sentence']);
	}//end testAnOpenApprovalSaysWhoItWaitsOn()

	/**
	 * An open approval with no approvers named says to look in decidiq.
	 *
	 * @return void
	 */
	public function testAnOpenApprovalWithoutApproversSaysToLookInDecidiq(): void {
		$this->gatedCaseType();
		$this->decisions->method('readDecisionState')->willReturn([
			'state' => ContractDecisionDelegationService::DECISION_STATE_OPEN,
			'envelope' => ['approvers' => ['', '  ']],
		]);

		$verdict = $this->gate->verdictFor(case: $this->linkedCase(), act: 'send-besluit', userId: 'behandelaar');

		self::assertSame([], $verdict['approvers']);
		self::assertSame(
			'Teamleider approval is still open. decidiq did not say who it is waiting on. Open the approval there to see.',
			$verdict['sentence']
		);
	}//end testAnOpenApprovalWithoutApproversSaysToLookInDecidiq()

	/**
	 * A gated act with no decision linked is outstanding and decidiq is not asked.
	 *
	 * @return void
	 */
	public function testAnApprovalNeverAskedForIsOutstanding(): void {
		$this->gatedCaseType();
		$this->decisions->expects(self::never())->method('readDecisionState');

		$verdict = $this->gate->verdictFor(case: ['id' => 'case-1', 'caseType' => 'ct-1'], act: 'send-besluit', userId: 'behandelaar');

		self::assertSame(
			[
				'gated' => true,
				'allowed' => false,
				'rule' => ApprovalGate::RULE_OUTSTANDING,
				'sentence' => 'Teamleider approval has not been asked for yet. Start it in decidiq, then come back to this act.',
				'status' => RefusedException::STATUS_UNPROCESSABLE,
				'label' => 'Teamleider approval',
				'decisionRef' => '',
				'approvers' => [],
			],
			$verdict
		);
	}//end testAnApprovalNeverAskedForIsOutstanding()

	/**
	 * An act the case type does not gate is allowed and ungated.
	 *
	 * @return void
	 */
	public function testAnUngatedActIsAllowed(): void {
		$this->gatedCaseType();
		$this->decisions->expects(self::never())->method('readDecisionState');

		$verdict = $this->gate->verdictFor(case: $this->linkedCase(), act: 'close-case', userId: 'behandelaar');

		self::assertFalse($verdict['gated']);
		self::assertTrue($verdict['allowed']);
		self::assertSame('', $verdict['rule']);
	}//end testAnUngatedActIsAllowed()

	/**
	 * A case type that cannot be read refuses as indeterminate and logs the case type id.
	 *
	 * @return void
	 */
	public function testAnUnreadableCaseTypeIsIndeterminate(): void {
		$this->caseTypes->method('effectiveCaseType')->willThrowException(new RuntimeException('register down'));
		$this->logger->expects(self::once())->method('warning')
			->with('Dossiq approval gate: the case type could not be read', ['caseType' => 'ct-1', 'exception' => 'register down']);

		$verdict = $this->gate->verdictFor(case: $this->linkedCase(), act: 'send-besluit', userId: 'behandelaar');

		self::assertFalse($verdict['allowed']);
		self::assertSame(ApprovalGate::RULE_UNREADABLE, $verdict['rule']);
		self::assertSame(RefusedException::STATUS_INDETERMINATE, $verdict['status']);
		self::assertSame(
			'We could not read this case type, so we cannot tell whether this act needs an approval. Try again shortly.',
			$verdict['sentence']
		);
	}//end testAnUnreadableCaseTypeIsIndeterminate()

	/**
	 * An unreadable decidiq answer keeps the act closed, says the service did not answer, and logs it.
	 *
	 * @return void
	 */
	public function testAnUnreadableAnswerKeepsTheActClosed(): void {
		$this->gatedCaseType();
		$this->decisions->method('readDecisionState')->willReturn(['state' => ContractDecisionDelegationService::DECISION_STATE_UNREADABLE]);
		$this->logger->expects(self::once())->method('warning')->with(
			'Dossiq approval gate: the approval outcome could not be read, so the act stays refused',
			['decisionRef' => 'dec-1', 'state' => 'unreadable', 'act' => 'send-besluit'],
		);

		$verdict = $this->gate->verdictFor(case: $this->linkedCase(), act: 'send-besluit', userId: 'behandelaar');

		self::assertFalse($verdict['allowed']);
		self::assertSame(ApprovalGate::RULE_UNREADABLE, $verdict['rule']);
		self::assertSame(RefusedException::STATUS_INDETERMINATE, $verdict['status']);
		self::assertSame(
			'We could not reach the approval service, so Teamleider approval could not be checked. This act stays closed until it answers.',
			$verdict['sentence']
		);
	}//end testAnUnreadableAnswerKeepsTheActClosed()

	/**
	 * The waiting list holds every declared gate that is not open, and skips the approved and the disabled ones.
	 *
	 * @return void
	 */
	public function testAwaitingListsTheGatesThatAreNotOpen(): void {
		$this->caseTypes->method('effectiveCaseType')->willReturn([
			ApprovalGateDeclaration::DECLARATION => [
				['act' => 'send-besluit', 'decisionType' => 'besluit-approval', 'label' => 'Teamleider approval'],
				['act' => 'close-case', 'decisionType' => 'close-approval'],
				['act' => 'archive', 'decisionType' => 'archive-approval', 'enabled' => false],
			],
		]);
		$this->decisions->method('readDecisionState')->willReturnCallback(
			static fn (string $decisionId): array => match ($decisionId) {
				'dec-1' => ['state' => ContractDecisionDelegationService::DECISION_STATE_DECIDED, 'status' => 'approved', 'envelope' => []],
				default => ['state' => ContractDecisionDelegationService::DECISION_STATE_OPEN, 'envelope' => ['approvers' => ['Anna']]],
			}
		);
		$case = $this->linkedCase();
		$case[ApprovalGateDeclaration::REFERENCES][] = ['act' => 'close-case', 'decisionRef' => 'dec-2'];

		$waiting = $this->gate->awaiting(case: $case, userId: 'behandelaar');

		self::assertSame(
			[
				[
					'act' => 'close-case',
					'label' => 'close-approval',
					'sentence' => 'close-approval is still open. It is waiting on Anna.',
					'approvers' => ['Anna'],
					'decisionRef' => 'dec-2',
				],
			],
			$waiting
		);
	}//end testAwaitingListsTheGatesThatAreNotOpen()

	/**
	 * A case type with gates declared on it, named `ct-1`.
	 *
	 * @return void
	 */
	private function gatedCaseType(): void {
		$this->caseTypes->method('effectiveCaseType')->with('ct-1')->willReturn([
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
				['act' => 'send-besluit', 'decisionRef' => 'dec-1'],
			],
		];
	}//end linkedCase()
}//end class
