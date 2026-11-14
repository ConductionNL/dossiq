<?php

/**
 * Unit tests for CaseActionList: engine transitions are published as
 * OpenRegister actions (failed guards blocking with their sentences, a closing
 * step asking for its result), and every action is blocked with one sentence
 * when the work lives elsewhere or the case waits for payment.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Lifecycle;

use OCA\Dossiq\Lifecycle\CaseActionList;
use OCA\Dossiq\Service\Cases\ExternalHome;
use OCA\Dossiq\Service\Money\CasePaymentReader;
use OCA\Dossiq\Service\Money\UnpaidCaseGate;
use OCA\Dossiq\Service\Transitions\CaseResultWriter;
use OCA\Dossiq\Service\Transitions\CaseTypeReader;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Lifecycle\CaseActionList
 */
class CaseActionListTest extends TestCase {

	private CaseResultWriter&MockObject $results;

	private ExternalHome&MockObject $home;

	private CaseTypeReader&MockObject $caseTypes;

	private UnpaidCaseGate&MockObject $unpaid;

	private CasePaymentReader&MockObject $payments;

	protected function setUp(): void {
		parent::setUp();
		$this->results = $this->createMock(CaseResultWriter::class);
		$this->home = $this->createMock(ExternalHome::class);
		$this->caseTypes = $this->createMock(CaseTypeReader::class);
		$this->unpaid = $this->createMock(UnpaidCaseGate::class);
		$this->payments = $this->createMock(CasePaymentReader::class);
	}//end setUp()

	private function list(?IL10N $l10n = null): CaseActionList {
		return new CaseActionList($this->results, $this->home, $this->caseTypes, $this->unpaid, $this->payments, $l10n);
	}//end list()

	public function testAnOpenTransitionIsPublishedAsAnAction(): void {
		$this->results->method('isFinalStatus')->with('st-review')->willReturn(false);

		$this->assertSame(
			[['action' => 'start-review', 'to' => 'st-review', 'requires' => null, 'description' => 'Begin de beoordeling.', 'inputs' => [], 'label' => 'Beoordelen', 'blocked' => false]],
			$this->list()->publishAll([['id' => 'start-review', 'toStatus' => 'st-review', 'label' => 'Beoordelen', 'description' => ' Begin de beoordeling. ']])
		);
	}//end testAnOpenTransitionIsPublishedAsAnAction()

	public function testAFailedTransitionIsBlockedWithItsGuardSentences(): void {
		$this->results->method('isFinalStatus')->willReturn(false);

		$actions = $this->list()->publishAll(
			[
				[
					'id' => 'decide',
					'toStatus' => 'st-decided',
					'guardsPassed' => false,
					'description' => 'Neem een besluit.',
					'failedGuards' => [['failureMessage' => 'Het advies ontbreekt.'], 'junk', ['failureMessage' => ' '], ['failureMessage' => 'De termijn loopt nog.']],
				],
				['id' => 'park', 'guardsPassed' => false, 'description' => 'Parkeer de zaak.', 'failedGuards' => []],
			]
		);

		$this->assertTrue($actions[0]['blocked']);
		$this->assertSame('Het advies ontbreekt. De termijn loopt nog.', $actions[0]['description']);
		$this->assertTrue($actions[1]['blocked']);
		$this->assertSame('Parkeer de zaak.', $actions[1]['description']);
		$this->assertSame('', $actions[1]['to']);
	}//end testAFailedTransitionIsBlockedWithItsGuardSentences()

	public function testAClosingStepAsksForItsResult(): void {
		$this->results->method('isFinalStatus')->with('st-closed')->willReturn(true);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'nl:' . $text);

		$actions = $this->list($l10n)->publishAll(
			[['id' => 'close', 'toStatus' => 'st-closed', 'resultTypes' => [['id' => ' rt-1 ', 'name' => ' Toegekend '], ['id' => 'rt-2'], ['id' => ''], 'junk', ['name' => 'no id']]]]
		);

		$this->assertSame(
			[['field' => 'resultTypeId', 'required' => true, 'label' => 'nl:Result', 'options' => [['value' => 'rt-1', 'label' => 'Toegekend'], ['value' => 'rt-2', 'label' => 'rt-2']]]],
			$actions[0]['inputs']
		);
	}//end testAClosingStepAsksForItsResult()

	public function testAClosingStepWithoutTranslatorOrResultTypesStillAsksForAResult(): void {
		$this->results->method('isFinalStatus')->willReturn(true);

		$this->assertSame(
			[['field' => 'resultTypeId', 'required' => true]],
			$this->list()->publishAll([['id' => 'close', 'toStatus' => 'st-closed', 'resultTypes' => 'none']])[0]['inputs']
		);
	}//end testAClosingStepWithoutTranslatorOrResultTypesStillAsksForAResult()

	public function testEntriesWithoutAnIdAreNotActions(): void {
		$this->results->expects($this->never())->method('isFinalStatus');

		$this->assertSame([], $this->list()->publishAll([['toStatus' => 'st-x'], 'junk', ['id' => '']]));
	}//end testEntriesWithoutAnIdAreNotActions()

	public function testWorkThatLivesElsewhereBlocksEveryAction(): void {
		$case = ['id' => 'case-1'];
		$this->home->method('whereTheWorkIs')->with($case)->willReturn('Deze zaak wordt behandeld in het zaaksysteem van de provincie.');

		$this->assertSame(
			[
				['action' => 'a', 'blocked' => true, 'description' => 'Deze zaak wordt behandeld in het zaaksysteem van de provincie.'],
				['action' => 'b', 'blocked' => true, 'description' => 'Deze zaak wordt behandeld in het zaaksysteem van de provincie.'],
			],
			$this->list()->honourExternalHome([['action' => 'a', 'blocked' => false, 'description' => 'x'], ['action' => 'b']], $case)
		);
	}//end testWorkThatLivesElsewhereBlocksEveryAction()

	public function testWorkThatLivesHereLeavesTheActionsAlone(): void {
		$this->home->method('whereTheWorkIs')->willReturn('');
		$actions = [['action' => 'a', 'blocked' => false]];

		$this->assertSame($actions, $this->list()->honourExternalHome($actions, []));
	}//end testWorkThatLivesHereLeavesTheActionsAlone()

	public function testAnUnpaidCaseBlocksEveryActionWithTheGatesSentence(): void {
		$rule = ['requiresPayment' => true];
		$this->caseTypes->expects($this->exactly(2))->method('paymentRule')->with('ct-1')->willReturn($rule);
		$this->unpaid->method('requiresPayment')->with($rule)->willReturn(true);
		$this->payments->method('stateOf')->with('case-1')->willReturn(['paid' => false]);
		$this->unpaid->method('whyItWaits')->with(['paid' => false], $rule)->willReturn('De leges zijn nog niet betaald.');
		$list = $this->list();

		$this->assertSame('De leges zijn nog niet betaald.', $list->whyPaymentBlocks(['caseType' => ['@self' => ['id' => ' ct-1 ']]], 'case-1'));
		$this->assertSame(
			[['action' => 'a', 'blocked' => true, 'description' => 'De leges zijn nog niet betaald.']],
			$list->honourPaymentRule([['action' => 'a']], ['caseType' => 'ct-1'], 'case-1')
		);
	}//end testAnUnpaidCaseBlocksEveryActionWithTheGatesSentence()

	public function testACaseTypeWithoutAPaymentRuleBlocksNothingAndReadsNoPayments(): void {
		$this->caseTypes->method('paymentRule')->with('ct-1')->willReturn(null);
		$this->unpaid->method('requiresPayment')->with(null)->willReturn(false);
		$this->payments->expects($this->never())->method('stateOf');
		$actions = [['action' => 'a', 'blocked' => false]];

		$this->assertSame('', $this->list()->whyPaymentBlocks(['caseType' => ['id' => 'ct-1']], 'case-1'));
		$this->assertSame($actions, $this->list()->honourPaymentRule($actions, ['caseType' => 'ct-1'], 'case-1'));
	}//end testACaseTypeWithoutAPaymentRuleBlocksNothingAndReadsNoPayments()
}//end class
