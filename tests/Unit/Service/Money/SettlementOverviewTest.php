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
 * @spec openspec/changes/overview-cards-per-case-type/specs/case-page/spec.md
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
