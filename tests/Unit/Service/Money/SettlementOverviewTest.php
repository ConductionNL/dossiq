<?php

/**
 * The case page reads a case's settlements through its applications and their
 * executions: a settlement names the execution, the execution the
 * application, and only the application names the case.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Money
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-page/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Money;

use OCA\Dossiq\Service\Money\SettlementOverview;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\AnonymousRefusingRegister;
use PHPUnit\Framework\TestCase;

/**
 * The settlement card on the Overview tab of a grant case.
 *
 * @covers \OCA\Dossiq\Service\Money\SettlementOverview
 * @uses \OCA\Dossiq\Command\Backfill\OpenRegisterRowNormaliser
 */
class SettlementOverviewTest extends TestCase {

	private AnonymousRefusingRegister $register;

	protected function setUp(): void {
		$this->register = new AnonymousRefusingRegister(actor: fn (): string => 'sanne');
		$this->register->seed(schema: 'subsidieAanvraag', id: 'application-1', row: ['case' => 'case-1']);
		$this->register->seed(schema: 'subsidieAanvraag', id: 'application-other', row: ['case' => 'case-2']);
		$this->register->seed(schema: 'subsidieUitvoering', id: 'execution-1', row: ['subsidieaanvraag' => 'application-1']);
		$this->register->seed(schema: 'subsidieUitvoering', id: 'execution-other', row: ['subsidieaanvraag' => 'application-other']);
		$this->register->seed(
			schema: 'subsidieVaststelling',
			id: 'settlement-2025',
			row: [
				'subsidieuitvoering' => 'execution-1',
				'determinationDate' => '2025-12-15T00:00:00+00:00',
				'actualCostTotal' => 23500,
				'determinedAmount' => '22000.456',
				'recoveryTrigger' => true,
				'status' => 'determined',
			]
		);
		$this->register->seed(
			schema: 'subsidieVaststelling',
			id: 'settlement-2026',
			row: [
				'subsidieuitvoering' => 'execution-1',
				'determinationDate' => '2026-06-30',
				'actualCostTotal' => 12000.5,
				'determinedAmount' => 12000,
				'status' => 'determined',
			]
		);
		$this->register->seed(
			schema: 'subsidieVaststelling',
			id: 'settlement-draft',
			row: ['subsidieuitvoering' => 'execution-1', 'actualCostTotal' => 800, 'status' => 'concept']
		);
		$this->register->seed(
			schema: 'subsidieVaststelling',
			id: 'settlement-elsewhere',
			row: ['subsidieuitvoering' => 'execution-other', 'actualCostTotal' => 1, 'status' => 'determined']
		);
	}//end setUp()

	/**
	 * Only the case's own settlements, two references away, the draft first
	 * and then the latest settlement date first.
	 *
	 * @return void
	 */
	public function testTheCaseSettlementsAreReachedThroughApplicationAndExecution(): void {
		$result = $this->overview()->forCase(caseId: 'case-1');

		$this->assertFalse($result['empty']);
		$this->assertSame(['settlement-draft', 'settlement-2026', 'settlement-2025'], array_column($result['settlements'], 'id'));
		$this->assertSame(['application-1'], array_values(array_unique(array_column($result['settlements'], 'applicationId'))));
		$this->assertSame(['execution-1'], array_values(array_unique(array_column($result['settlements'], 'executionId'))));
	}//end testTheCaseSettlementsAreReachedThroughApplicationAndExecution()

	/**
	 * A settlement reads its date, amounts, recovery and status as the card shows them.
	 *
	 * @return void
	 */
	public function testEachSettlementCarriesWhatTheCardShows(): void {
		[$draft, , $settled] = $this->overview()->forCase(caseId: 'case-1')['settlements'];

		$this->assertSame(
			[
				'id' => 'settlement-2025',
				'applicationId' => 'application-1',
				'executionId' => 'execution-1',
				'determinationDate' => '2025-12-15',
				'actualCostTotal' => 23500.0,
				'determinedAmount' => 22000.46,
				'recovery' => true,
				'status' => 'determined',
			],
			$settled
		);
		$this->assertSame('', $draft['determinationDate']);
		$this->assertNull($draft['determinedAmount']);
		$this->assertFalse($draft['recovery']);
		$this->assertSame('draft', $draft['status']);
	}//end testEachSettlementCarriesWhatTheCardShows()

	/**
	 * A case without an application, or with one not yet settled, has none.
	 *
	 * @return void
	 */
	public function testACaseWithoutSettlementIsEmpty(): void {
		$this->register->seed(schema: 'subsidieAanvraag', id: 'application-3', row: ['case' => 'case-3']);
		$this->register->seed(schema: 'subsidieUitvoering', id: 'execution-3', row: ['subsidieaanvraag' => 'application-3']);

		$this->assertSame(['empty' => true, 'settlements' => []], $this->overview()->forCase(caseId: 'case-3'));
		$this->assertSame(['empty' => true, 'settlements' => []], $this->overview()->forCase(caseId: 'case-none'));
	}//end testACaseWithoutSettlementIsEmpty()

	/**
	 * Without OpenRegister, or without a case id, nothing is read.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterOrCaseNothingIsRead(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);

		$this->assertSame(['empty' => true, 'settlements' => []], (new SettlementOverview(settings: $settings))->forCase(caseId: 'case-1'));
		$this->assertSame(['empty' => true, 'settlements' => []], $this->overview()->forCase(caseId: ''));
	}//end testWithoutOpenRegisterOrCaseNothingIsRead()

	/**
	 * The grant actions read every execution of the case with its granted
	 * amount, advances, interim reports and settlements.
	 *
	 * @return void
	 */
	public function testTheGrantChainCarriesWhatTheNextStepNeeds(): void {
		$this->seedChain();

		$result = $this->overview()->chainForCase(caseId: 'case-1');

		$this->assertFalse(condition: $result['empty']);
		$this->assertSame(expected: ['execution-1'], actual: array_column(array: $result['executions'], column_key: 'id'));
		$execution = $result['executions'][0];
		$this->assertSame(expected: 'application-1', actual: $execution['applicationId']);
		$this->assertSame(expected: 'SUB-2026-1', actual: $execution['applicationNumber']);
		$this->assertSame(expected: 'in_execution', actual: $execution['status']);
		$this->assertSame(expected: 50000.0, actual: $execution['grantedAmount']);
		$this->assertSame(expected: 40000.0, actual: $execution['advancesPaid']);
		$this->assertSame(expected: ['report-1', 'report-2'], actual: array_column(array: $execution['interimReports'], column_key: 'id'));
		$this->assertSame(
			expected: ['settlement-2025', 'settlement-2026', 'settlement-draft'],
			actual: array_column(array: $execution['settlements'], column_key: 'id')
		);
		$this->assertSame(expected: 'draft', actual: $execution['settlements'][2]['status']);
	}//end testTheGrantChainCarriesWhatTheNextStepNeeds()

	/**
	 * An interim report reads its number, period, state and whether it was assessed.
	 *
	 * @return void
	 */
	public function testAnInterimReportSaysWhetherItWasAssessed(): void {
		$this->seedChain();

		[$open, $assessed] = $this->overview()->chainForCase(caseId: 'case-1')['executions'][0]['interimReports'];

		$this->assertSame(
			expected: [
				'id' => 'report-1',
				'number' => 'TR-1',
				'periodStart' => '2026-01-01',
				'periodEnd' => '2026-06-30',
				'status' => 'expected',
				'opinion' => '',
				'assessed' => false,
			],
			actual: $open
		);
		$this->assertTrue(condition: $assessed['assessed']);
		$this->assertSame(expected: 'voldoende', actual: $assessed['opinion']);
	}//end testAnInterimReportSaysWhetherItWasAssessed()

	/**
	 * Without a decision of its own, an execution takes the granted amount
	 * only from the single decision of its application that names one.
	 *
	 * @return void
	 */
	public function testTheGrantedAmountFallsBackOnlyToASingleDecision(): void {
		$this->seedChain();
		$this->register->seed(schema: 'subsidieUitvoering', id: 'execution-1', row: ['subsidieaanvraag' => 'application-1']);
		$this->assertNull(actual: $this->overview()->chainForCase(caseId: 'case-1')['executions'][0]['grantedAmount']);

		$this->register->seed(schema: 'subsidieAanvraag', id: 'application-3', row: ['case' => 'case-3']);
		$this->register->seed(
			schema: 'subsidieBeschikking',
			id: 'decision-3',
			row: ['subsidieaanvraag' => 'application-3', 'grantedAmount' => '12500.255']
		);
		$this->register->seed(
			schema: 'subsidieUitvoering',
			id: 'execution-3',
			row: ['subsidieaanvraag' => 'application-3', 'betaaldeVoorschotten' => 'n/a']
		);
		$execution = $this->overview()->chainForCase(caseId: 'case-3')['executions'][0];
		$this->assertSame(expected: 12500.26, actual: $execution['grantedAmount']);
		$this->assertNull(actual: $execution['advancesPaid']);
		$this->assertSame(expected: [], actual: $execution['interimReports']);
		$this->assertSame(expected: [], actual: $execution['settlements']);
	}//end testTheGrantedAmountFallsBackOnlyToASingleDecision()

	/**
	 * A case without an execution, or without a case id, has an empty chain.
	 *
	 * @return void
	 */
	public function testWithoutAnExecutionTheChainIsEmpty(): void {
		$this->register->seed(schema: 'subsidieAanvraag', id: 'application-4', row: ['case' => 'case-4']);

		$this->assertSame(expected: ['empty' => true, 'executions' => []], actual: $this->overview()->chainForCase(caseId: 'case-4'));
		$this->assertSame(expected: ['empty' => true, 'executions' => []], actual: $this->overview()->chainForCase(caseId: ''));
	}//end testWithoutAnExecutionTheChainIsEmpty()

	/**
	 * The rows the grant chain reads beside the settlements of setUp().
	 *
	 * @return void
	 */
	private function seedChain(): void {
		$this->register->seed(schema: 'subsidieAanvraag', id: 'application-1', row: ['case' => 'case-1', 'aanvraagnummer' => 'SUB-2026-1']);
		$this->register->seed(schema: 'subsidieBeschikking', id: 'decision-1', row: ['subsidieaanvraag' => 'application-1', 'grantedAmount' => 50000]);
		$this->register->seed(schema: 'subsidieBeschikking', id: 'decision-2', row: ['subsidieaanvraag' => 'application-1', 'grantedAmount' => 12500]);
		$this->register->seed(
			schema: 'subsidieUitvoering',
			id: 'execution-1',
			row: ['subsidieaanvraag' => 'application-1', 'subsidyDecision' => 'decision-1', 'status' => 'in_execution', 'betaaldeVoorschotten' => '40000']
		);
		$this->register->seed(
			schema: 'interimReport',
			id: 'report-1',
			row: [
				'subsidieuitvoering' => 'execution-1',
				'rapportagenummer' => 'TR-1',
				'rapportagePeriodStart' => '2026-01-01T00:00:00+00:00',
				'rapportagePeriodEnd' => '2026-06-30',
				'status' => 'expected',
			]
		);
		$this->register->seed(
			schema: 'interimReport',
			id: 'report-2',
			row: ['subsidieuitvoering' => 'execution-1', 'status' => 'approved', 'beoordelingsoordeel' => 'voldoende']
		);
		$this->register->seed(schema: 'interimReport', id: 'report-elsewhere', row: ['subsidieuitvoering' => 'execution-other']);
	}//end seedChain()

	/**
	 * The overview over the in-memory register.
	 *
	 * @return SettlementOverview
	 */
	private function overview(): SettlementOverview {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);

		return new SettlementOverview(settings: $settings);
	}//end overview()
}//end class
