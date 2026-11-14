<?php

/**
 * The case page reads a case's payment schedule: each instalment with its
 * state, in date order, and the totals beside them.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Money
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/grant-cases-from-application-to-settlement/specs/subsidieverlening-keten/spec.md#requirement-a-handler-runs-a-grant-from-its-case-page-req-sub-case-01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Money;

use DateTimeImmutable;
use OCA\Dossiq\Service\Money\PaymentScheduleOverview;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\AnonymousRefusingRegister;
use PHPUnit\Framework\TestCase;

/**
 * REQ-SUB-CASE-01, the advance schedule on the case page.
 *
 * @covers \OCA\Dossiq\Service\Money\PaymentScheduleOverview
 * @uses \OCA\Dossiq\Command\Backfill\OpenRegisterRowNormaliser
 */
class PaymentScheduleOverviewTest extends TestCase {

	private AnonymousRefusingRegister $register;

	protected function setUp(): void {
		$this->register = new AnonymousRefusingRegister(actor: fn (): string => 'sanne');
		$this->register->seed(
			schema: 'beschikking',
			id: 'decision-1',
			row: [
				'caseId' => 'case-1',
				'paymentSchedule' => [
					'instalments' => [
						['label' => 'Restant', 'amountCents' => 480000, 'condition' => 'Eindverantwoording goedgekeurd'],
						['label' => '4e voorschot', 'dueOn' => '2027-01-01', 'amountCents' => 240000],
						['label' => '3e voorschot', 'dueOn' => '2026-11-01', 'amountCents' => 480000, 'condition' => 'Tussenrapportage goedgekeurd', 'conditionMet' => true],
						['label' => '1e voorschot', 'dueOn' => '2026-03-01', 'amountCents' => 480000, 'payableId' => 'payable-1', 'raisedAt' => '2026-03-03'],
						['label' => '2e voorschot', 'dueOn' => '2026-06-01', 'amountCents' => 720000, 'payableId' => 'payable-2', 'raisedAt' => '2026-06-02'],
					],
				],
			]
		);
		$this->register->seed(
			schema: 'beschikking',
			id: 'decision-other-case',
			row: [
				'caseId' => 'case-2',
				'paymentSchedule' => ['instalments' => [['label' => 'Elsewhere', 'dueOn' => '2026-01-01', 'amountCents' => 100]]],
			]
		);
	}//end setUp()

	/**
	 * The board's schedule: two paid, one ready, two planned, in date order.
	 *
	 * @return void
	 */
	public function testEachInstalmentCarriesItsStateInDateOrder(): void {
		$schedule = $this->overview()->forCase(caseId: 'case-1', today: new DateTimeImmutable('2026-10-30'));

		$this->assertSame(
			['1e voorschot', '2e voorschot', '3e voorschot', '4e voorschot', 'Restant'],
			array_column($schedule['instalments'], 'label')
		);
		$this->assertSame(
			['paid', 'paid', 'ready', 'planned', 'planned'],
			array_column($schedule['instalments'], 'state')
		);
		$this->assertFalse($schedule['empty']);
		$this->assertSame(4800.0, $schedule['instalments'][0]['amount']);
		$this->assertSame('2026-03-03', $schedule['instalments'][0]['raisedAt']);
		$this->assertSame('decision-1', $schedule['instalments'][0]['decisionId']);
		$this->assertSame('', $schedule['instalments'][4]['dueOn']);
	}//end testEachInstalmentCarriesItsStateInDateOrder()

	/**
	 * The totals match the board: 24.000 in all, 12.000 paid, 4.800 ready, 7.200 to pay.
	 *
	 * @return void
	 */
	public function testTheTotalsSplitWhatIsPaidReadyAndStillToPay(): void {
		$schedule = $this->overview()->forCase(caseId: 'case-1', today: new DateTimeImmutable('2026-10-30'));

		$this->assertSame(['total' => 24000.0, 'paid' => 12000.0, 'ready' => 4800.0, 'toPay' => 7200.0], $schedule['totals']);
	}//end testTheTotalsSplitWhatIsPaidReadyAndStillToPay()

	/**
	 * Past its day, an instalment without a condition is ready and one with an unmet condition waits.
	 *
	 * @return void
	 */
	public function testPastItsDayAnInstalmentIsReadyOrWaitsForItsCondition(): void {
		$this->register->seed(
			schema: 'beschikking',
			id: 'decision-1',
			row: [
				'caseId' => 'case-1',
				'paymentSchedule' => [
					'instalments' => [
						['label' => 'Due', 'dueOn' => '2027-01-01', 'amountCents' => 100],
						['label' => 'Held', 'dueOn' => '2027-01-01', 'amountCents' => 200, 'condition' => 'Report approved'],
					],
				],
			]
		);

		$schedule = $this->overview()->forCase(caseId: 'case-1', today: new DateTimeImmutable('2027-01-02'));

		$this->assertSame(['ready', 'waiting'], array_column($schedule['instalments'], 'state'));
		$this->assertSame(['total' => 3.0, 'paid' => 0.0, 'ready' => 1.0, 'toPay' => 2.0], $schedule['totals']);
	}//end testPastItsDayAnInstalmentIsReadyOrWaitsForItsCondition()

	/**
	 * A case without a scheduled decision, and an absent OpenRegister, answer an empty schedule.
	 *
	 * @return void
	 */
	public function testNoScheduleAnswersEmpty(): void {
		$empty = ['empty' => true, 'instalments' => [], 'totals' => ['total' => 0.0, 'paid' => 0.0, 'ready' => 0.0, 'toPay' => 0.0]];

		$this->assertSame($empty, $this->overview()->forCase(caseId: 'case-3', today: new DateTimeImmutable('2026-10-30')));

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);
		$this->assertSame($empty, (new PaymentScheduleOverview(settings: $settings))->forCase(caseId: 'case-1', today: new DateTimeImmutable('2026-10-30')));
	}//end testNoScheduleAnswersEmpty()

	/**
	 * The overview over the in-memory register.
	 *
	 * @return PaymentScheduleOverview
	 */
	private function overview(): PaymentScheduleOverview {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);

		return new PaymentScheduleOverview(settings: $settings);
	}//end overview()
}//end class
