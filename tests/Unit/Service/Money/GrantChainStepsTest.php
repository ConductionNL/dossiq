<?php

/**
 * The grant actions of a case page read the case's executions through its
 * applications: an execution names the application, and only the application
 * names the case.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Money
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Money;

use OCA\Dossiq\Service\Money\GrantChainSteps;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\AnonymousRefusingRegister;
use PHPUnit\Framework\TestCase;

/**
 * The executions of a grant case with what their next step needs.
 *
 * @covers \OCA\Dossiq\Service\Money\GrantChainSteps
 * @uses \OCA\Dossiq\Command\Backfill\OpenRegisterRowNormaliser
 */
class GrantChainStepsTest extends TestCase {

	private AnonymousRefusingRegister $register;

	protected function setUp(): void {
		$this->register = new AnonymousRefusingRegister(actor: fn (): string => 'sanne');
		$this->register->seed(schema: 'subsidieAanvraag', id: 'application-1', row: ['case' => 'case-1', 'aanvraagnummer' => 'SUB-2026-1']);
		$this->register->seed(schema: 'subsidieAanvraag', id: 'application-other', row: ['case' => 'case-2']);
		$this->register->seed(schema: 'subsidieBeschikking', id: 'decision-1', row: ['subsidieaanvraag' => 'application-1', 'grantedAmount' => 50000]);
		$this->register->seed(schema: 'subsidieBeschikking', id: 'decision-2', row: ['subsidieaanvraag' => 'application-1', 'grantedAmount' => '12500.255']);
		$this->register->seed(
			schema: 'subsidieUitvoering',
			id: 'execution-1',
			row: ['subsidieaanvraag' => 'application-1', 'subsidyDecision' => 'decision-1', 'status' => 'in_execution', 'betaaldeVoorschotten' => '40000']
		);
		$this->register->seed(schema: 'subsidieUitvoering', id: 'execution-other', row: ['subsidieaanvraag' => 'application-other']);
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
			row: ['subsidieuitvoering' => 'execution-1', 'rapportagenummer' => 'TR-0', 'status' => 'approved', 'beoordelingsoordeel' => 'voldoende']
		);
		$this->register->seed(schema: 'interimReport', id: 'report-elsewhere', row: ['subsidieuitvoering' => 'execution-other']);
		$this->register->seed(
			schema: 'subsidieVaststelling',
			id: 'settlement-1',
			row: ['subsidieuitvoering' => 'execution-1', 'actualCostTotal' => 35000, 'status' => 'draft']
		);
	}//end setUp()

	/**
	 * Only the case's own executions, each with its own reports and settlements.
	 *
	 * @return void
	 */
	public function testTheCaseExecutionsCarryWhatTheirNextStepNeeds(): void {
		$result = $this->steps()->forCase(caseId: 'case-1');

		$this->assertFalse($result['empty']);
		$this->assertCount(1, $result['executions']);
		$execution = $result['executions'][0];
		$this->assertSame('execution-1', $execution['id']);
		$this->assertSame('application-1', $execution['applicationId']);
		$this->assertSame('SUB-2026-1', $execution['applicationNumber']);
		$this->assertSame('in_execution', $execution['status']);
		$this->assertSame(50000.0, $execution['grantedAmount']);
		$this->assertSame(40000.0, $execution['advancesPaid']);
		$this->assertSame(['report-1', 'report-2'], array_column($execution['interimReports'], 'id'));
		$this->assertSame(
			['id' => 'settlement-1', 'actualCostTotal' => 35000.0, 'determinedAmount' => null, 'determined' => false],
			$execution['settlements'][0]
		);
	}//end testTheCaseExecutionsCarryWhatTheirNextStepNeeds()

	/**
	 * A report reads its number, period, state and whether it was assessed.
	 *
	 * @return void
	 */
	public function testAReportSaysWhetherItWasAssessed(): void {
		[$open, $assessed] = $this->steps()->forCase(caseId: 'case-1')['executions'][0]['interimReports'];

		$this->assertSame(
			['id' => 'report-1', 'number' => 'TR-1', 'periodStart' => '2026-01-01', 'periodEnd' => '2026-06-30', 'status' => 'expected', 'opinion' => '', 'assessed' => false],
			$open
		);
		$this->assertTrue($assessed['assessed']);
		$this->assertSame('voldoende', $assessed['opinion']);
	}//end testAReportSaysWhetherItWasAssessed()

	/**
	 * Without its own decision, an execution takes the granted amount only
	 * when its application has exactly one decision that names one.
	 *
	 * @return void
	 */
	public function testTheGrantedAmountFallsBackOnlyToASingleDecision(): void {
		$this->register->seed(schema: 'subsidieUitvoering', id: 'execution-1', row: ['subsidieaanvraag' => 'application-1']);
		$this->assertNull($this->steps()->forCase(caseId: 'case-1')['executions'][0]['grantedAmount']);

		$this->register->seed(schema: 'subsidieAanvraag', id: 'application-3', row: ['case' => 'case-3']);
		$this->register->seed(schema: 'subsidieBeschikking', id: 'decision-3', row: ['subsidieaanvraag' => 'application-3', 'grantedAmount' => '12500.255']);
		$this->register->seed(schema: 'subsidieUitvoering', id: 'execution-3', row: ['subsidieaanvraag' => 'application-3', 'betaaldeVoorschotten' => 'n/a']);
		$execution = $this->steps()->forCase(caseId: 'case-3')['executions'][0];
		$this->assertSame(12500.26, $execution['grantedAmount']);
		$this->assertNull($execution['advancesPaid']);
		$this->assertSame([], $execution['interimReports']);
		$this->assertSame([], $execution['settlements']);
	}//end testTheGrantedAmountFallsBackOnlyToASingleDecision()

	/**
	 * A case without an execution, without OpenRegister or without an id has none.
	 *
	 * @return void
	 */
	public function testWithoutAnExecutionTheChainIsEmpty(): void {
		$this->register->seed(schema: 'subsidieAanvraag', id: 'application-4', row: ['case' => 'case-4']);
		$this->assertSame(['empty' => true, 'executions' => []], $this->steps()->forCase(caseId: 'case-4'));
		$this->assertSame(['empty' => true, 'executions' => []], $this->steps()->forCase(caseId: ''));

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);
		$this->assertSame(['empty' => true, 'executions' => []], (new GrantChainSteps(settings: $settings))->forCase(caseId: 'case-1'));
	}//end testWithoutAnExecutionTheChainIsEmpty()

	/**
	 * The steps over the in-memory register.
	 *
	 * @return GrantChainSteps
	 */
	private function steps(): GrantChainSteps {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);

		return new GrantChainSteps(settings: $settings);
	}//end steps()
}//end class
