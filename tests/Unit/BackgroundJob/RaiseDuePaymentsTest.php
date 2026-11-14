<?php

/**
 * An instalment of a decision's payment schedule is raised once on its date.
 *
 * Driven through the real job and the real raiser over an in-memory register
 * that refuses writes from nobody; only the finance app is doubled.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\BackgroundJob
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/grant-cases-from-application-to-settlement/specs/subsidieverlening-keten/spec.md#requirement-advances-are-a-payment-schedule-raised-with-finance-when-due-req-sub-case-02
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\BackgroundJob;

use DateTime;
use OCA\Dossiq\BackgroundJob\RaiseDuePayments;
use OCA\Dossiq\Service\Money\PaymentScheduleRaiser;
use OCA\Dossiq\Service\Money\PayoutRequester;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\AnonymousRefusingRegister;
use OCA\Dossiq\Tests\Support\MakesBackgroundServiceAccount;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * REQ-SUB-CASE-02.
 *
 * @covers \OCA\Dossiq\BackgroundJob\RaiseDuePayments
 * @covers \OCA\Dossiq\Service\Money\PaymentScheduleRaiser
 * @uses \OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount
 * @uses \OCA\Dossiq\Service\ServiceAccount\ServiceAccount
 * @uses \OCA\Dossiq\Command\Backfill\OpenRegisterRowNormaliser
 */
class RaiseDuePaymentsTest extends TestCase {
	use MakesBackgroundServiceAccount;

	private AnonymousRefusingRegister $register;

	/** @var list<array<string, mixed>> */
	private array $raised = [];

	private string $today = '2027-01-01 08:00:00';

	protected function setUp(): void {
		$this->register = $this->refusingRegister();
		$this->register->seed(
			schema: 'beschikking',
			id: 'decision-1',
			row: [
				'caseId' => 'case-1',
				'reference' => 'SUB-2026-0112',
				'addressee' => ['name' => 'Buurtvereniging Oost'],
				'paymentSchedule' => [
					'payeeIban' => 'NL91ABNA0417164300',
					'instalments' => [
						['label' => '1e voorschot', 'dueOn' => '2027-01-01', 'amountCents' => 480000],
						['label' => '2e voorschot', 'dueOn' => '2027-07-01', 'amountCents' => 720000],
						['label' => '3e voorschot', 'dueOn' => '2026-11-01', 'amountCents' => 480000, 'condition' => 'Tussenrapportage goedgekeurd'],
					],
				],
			]
		);
	}//end setUp()

	/**
	 * Two runs on 1 January raise the January instalment once.
	 *
	 * @return void
	 */
	public function testAnInstalmentIsRaisedOnceOnItsDate(): void {
		$job = $this->job();
		$this->runJobOnce(job: $job);
		$this->runJobOnce(job: $job);

		$this->assertCount(1, $this->raised);
		$this->assertSame(480000, $this->raised[0]['amountCents']);
		$this->assertSame('Buurtvereniging Oost', $this->raised[0]['payeeName']);
		$this->assertSame('NL91ABNA0417164300', $this->raised[0]['iban']);
		$this->assertSame('SUB-2026-0112-1', $this->raised[0]['reference']);

		$instalments = $this->register->row(schema: 'beschikking', id: 'decision-1')['paymentSchedule']['instalments'];
		$this->assertSame('payable-1', $instalments[0]['payableId']);
		$this->assertSame('2027-01-01', $instalments[0]['raisedAt']);
		$this->assertArrayNotHasKey('payableId', $instalments[1]);
		$this->assertWroteAsTheServiceAccount(register: $this->register, schemas: ['beschikking']);
	}//end testAnInstalmentIsRaisedOnceOnItsDate()

	/**
	 * An instalment with an unmet condition waits; once met it is raised.
	 *
	 * @return void
	 */
	public function testAConditionHoldsTheInstalmentUntilItIsMet(): void {
		$this->runJobOnce(job: $this->job());
		$this->assertSame(['SUB-2026-0112-1'], array_column($this->raised, 'reference'));

		$row = $this->register->row(schema: 'beschikking', id: 'decision-1');
		$row['paymentSchedule']['instalments'][2]['conditionMet'] = true;
		$this->register->seed(schema: 'beschikking', id: 'decision-1', row: $row);

		$this->runJobOnce(job: $this->job());
		$this->assertSame(['SUB-2026-0112-1', 'SUB-2026-0112-3'], array_column($this->raised, 'reference'));
	}//end testAConditionHoldsTheInstalmentUntilItIsMet()

	/**
	 * A refusal by the finance app leaves the instalment unraised for the next run.
	 *
	 * @return void
	 */
	public function testARefusedInstalmentStaysOpen(): void {
		$this->runJobOnce(job: $this->job(refuse: true));

		$instalments = $this->register->row(schema: 'beschikking', id: 'decision-1')['paymentSchedule']['instalments'];
		$this->assertArrayNotHasKey('payableId', $instalments[0]);
	}//end testARefusedInstalmentStaysOpen()

	/**
	 * The real job over the real raiser.
	 *
	 * @param bool $refuse Whether the finance app refuses.
	 *
	 * @return RaiseDuePayments
	 */
	private function job(bool $refuse = false): RaiseDuePayments {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);

		$payouts = $this->createMock(PayoutRequester::class);
		$payouts->method('request')->willReturnCallback(function (array $payout) use ($refuse): array {
			if ($refuse === true) {
				throw new \RuntimeException('refused');
			}

			$this->raised[] = $payout;
			return ['payableId' => 'payable-' . count($this->raised), 'state' => 'open'];
		});

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(fn (): DateTime => new DateTime($this->today));

		return new RaiseDuePayments(
			time: $time,
			raiser: new PaymentScheduleRaiser(settings: $settings, payouts: $payouts, logger: new NullLogger()),
			serviceAccount: $this->backgroundAccount(),
		);
	}//end job()
}//end class
