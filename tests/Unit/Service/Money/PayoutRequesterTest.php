<?php

/**
 * PayoutRequester tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Money
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
 * @spec openspec/changes/payout-goes-through-finance/specs/payout/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Money;

use OCA\Dossiq\Service\Money\PayoutReader;
use OCA\Dossiq\Service\Money\PayoutRequester;
use OCA\Dossiq\Service\Money\PayoutService;
use OCA\Shillinq\Event\PayableCancelRequestedEvent;
use OCA\Shillinq\Event\PayableRaisedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Money\PayoutRequester
 */
class PayoutRequesterTest extends TestCase {

	/**
	 * A payout as the payout service hands it over.
	 *
	 * @var array{amountCents: int, payeeName: string, iban: string, reference: string}
	 */
	private const PAYOUT = ['amountCents' => 144200, 'payeeName' => 'J. Burger', 'iban' => 'NL91ABNA0417164300', 'reference' => 'PROC-DWS-1'];

	/**
	 * Nobody answering (finance app installed without a listener, or disabled)
	 * is not a refusal: both commands answer null.
	 *
	 * @return void
	 */
	public function testNoAnswerIsNull(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->exactly(2))->method('dispatchTyped');
		$requester = new PayoutRequester(dispatcher: $dispatcher);

		self::assertNull($requester->request(payout: self::PAYOUT));
		self::assertNull($requester->cancel(reference: 'PROC-DWS-1', reason: 'Bezwaar'));
	}//end testNoAnswerIsNull()

	/**
	 * An accepted command answers the payable; the amount travels in euros
	 * from dossiq's own app id.
	 *
	 * @return void
	 */
	public function testAnAcceptedPayoutAnswersThePayable(): void {
		$seen = null;
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (object $event) use (&$seen): void {
				$seen = $event;
				$event->accept('p-9', 'requested');
			}
		);

		$answer = (new PayoutRequester(dispatcher: $dispatcher))->request(payout: self::PAYOUT);

		self::assertSame(['payableId' => 'p-9', 'state' => 'requested'], $answer);
		self::assertInstanceOf(PayableRaisedEvent::class, $seen);
		self::assertSame(1442.0, $seen->toArray()['amount']);
		self::assertSame('dossiq', $seen->toArray()['sourceApp']);
	}//end testAnAcceptedPayoutAnswersThePayable()

	/**
	 * The linked objects reach the finance app with the payout (REQ-DQM-002).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-002)
	 */
	public function testTheLinkedObjectsReachFinance(): void {
		$seen = null;
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (object $event) use (&$seen): void {
				$seen = $event;
				$event->accept('payable-1', 'requested');
			}
		);
		$case = ['app' => 'dossiq', 'type' => 'case', 'register' => 'dossiq', 'schema' => 'case', 'id' => 'case-7'];

		(new PayoutRequester(dispatcher: $dispatcher))->request(payout: self::PAYOUT + ['linkedObjects' => [$case]]);

		self::assertSame([$case], $seen->toArray()['linkedObjects']);
	}//end testTheLinkedObjectsReachFinance()

	/**
	 * A payout paid before finance kept it goes over with its history (REQ-DQM-004).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-004)
	 */
	public function testAHistoricalPayoutCarriesItsHistory(): void {
		$seen = null;
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (object $event) use (&$seen): void {
				$seen = $event;
				$event->accept('payable-1', 'paid');
			}
		);

		(new PayoutRequester(dispatcher: $dispatcher))->request(payout: self::PAYOUT + ['historicalState' => 'paid', 'paidOn' => '2026-03-03', 'bankReference' => 'ERP-9']);

		self::assertSame('paid', $seen->toArray()['historicalState']);
		self::assertSame('2026-03-03', $seen->toArray()['paidOn']);
		self::assertSame('ERP-9', $seen->toArray()['bankReference']);
	}//end testAHistoricalPayoutCarriesItsHistory()

	/**
	 * The finance app answers with the payouts dossiq raised; no answer is null (REQ-DQM-004).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-004)
	 */
	public function testTheReaderAsksFinanceForDossiqsPayouts(): void {
		$asked = null;
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (object $event) use (&$asked): void {
				$asked = $event;
				$event->answer([['payableId' => 'payable-1', 'reference' => 'DWS-1', 'state' => 'paid']]);
			}
		);

		$payouts = (new PayoutReader(dispatcher: $dispatcher))->payouts(correlationIds: ['calc-1'], paidInYear: 2026);

		self::assertSame([['payableId' => 'payable-1', 'reference' => 'DWS-1', 'state' => 'paid']], $payouts);
		self::assertSame('dossiq', $asked->getSourceApp());
		self::assertSame(['calc-1'], $asked->getCorrelationIds());
		self::assertSame(2026, $asked->getPaidInYear());
		self::assertSame(PayoutService::CATEGORY, $asked->getCategory());

		$silent = $this->createMock(IEventDispatcher::class);
		self::assertNull((new PayoutReader(dispatcher: $silent))->payouts());
	}//end testTheReaderAsksFinanceForDossiqsPayouts()

	/**
	 * Finance's payouts in the row shape dossiq kept, beside the rows finance
	 * does not hold; a withdrawn payout is no payment (REQ-DQM-004).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-004)
	 */
	public function testMergedRowsAreFinancesPayoutsAndTheRowsFinanceDoesNotHold(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (object $event): void {
				$event->answer([
					['payableId' => 'payable-1', 'reference' => 'DWS-1', 'correlationId' => 'calc-1', 'amount' => 357.0, 'state' => 'paid', 'paidOn' => '2026-03-03', 'bankReference' => 'ERP-9', 'payee' => ['name' => 'J. Burger', 'iban' => 'NL91ABNA0417164300']],
					['payableId' => 'payable-2', 'reference' => 'DWS-2', 'correlationId' => 'calc-2', 'amount' => 100.0, 'state' => 'cancelled', 'paidOn' => '', 'bankReference' => ''],
					['payableId' => 'payable-3', 'reference' => 'DWS-3', 'correlationId' => 'calc-3', 'amount' => 50.5, 'state' => 'approved', 'paidOn' => '', 'bankReference' => ''],
				]);
			}
		);
		$stored = [
			['id' => 'u-1', 'reference' => 'DWS-1', 'payableId' => 'payable-1', 'status' => 'paid', 'amount' => 35700],
			['id' => 'u-2', 'reference' => 'DWS-2', 'payableId' => '', 'status' => 'on-hold-objection', 'amount' => 10000, 'penaltyPaymentCalculation' => 'calc-2'],
		];

		$rows = (new PayoutReader(dispatcher: $dispatcher))->mergedRows(storedRows: $stored);

		self::assertSame(['DWS-1', 'DWS-3', 'DWS-2'], array_column($rows, 'reference'));
		self::assertSame(
			['id' => 'payable-1', 'payableId' => 'payable-1', 'penaltyPaymentCalculation' => 'calc-1', 'reference' => 'DWS-1', 'amount' => 35700, 'status' => 'paid', 'actualPaymentDate' => '2026-03-03', 'betalingsreferentie' => 'ERP-9', 'accountHolderName' => 'J. Burger', 'iban' => 'NL91ABNA0417164300', 'legalBasis' => 'AWB 4:17'],
			$rows[0]
		);
		self::assertSame('in-handling', $rows[1]['status']);
		self::assertSame(5050, $rows[1]['amount']);
		self::assertSame('on-hold-objection', $rows[2]['status'], 'the stored row under objection stays');

		$silent = $this->createMock(IEventDispatcher::class);
		self::assertSame($stored, (new PayoutReader(dispatcher: $silent))->mergedRows(storedRows: $stored), 'no finance app: the stored rows');
	}//end testMergedRowsAreFinancesPayoutsAndTheRowsFinanceDoesNotHold()

	/**
	 * A refusal is an error naming finance's reason.
	 *
	 * @return void
	 */
	public function testARefusalThrows(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (object $event): void {
				self::assertInstanceOf(PayableCancelRequestedEvent::class, $event);
				$event->refuse('The payable under reference PROC-DWS-1 is approved, so only finance can undo it.');
			}
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('approved');
		(new PayoutRequester(dispatcher: $dispatcher))->cancel(reference: 'PROC-DWS-1', reason: 'Bezwaar');
	}//end testARefusalThrows()
}//end class
