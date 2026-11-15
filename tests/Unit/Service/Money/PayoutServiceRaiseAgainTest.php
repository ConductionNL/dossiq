<?php

/**
 * PayoutService input and raise-again tests.
 *
 * A payout prepared from request input carries the receipt date into its
 * payment deadline, and raising a payout again only happens for a row that is
 * out of finance with something left to pay; without a finance answer the
 * row keeps the reference the payment provider already knows.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Money;

use InvalidArgumentException;
use OCA\Dossiq\Service\Money\PayoutRequester;
use OCA\Dossiq\Service\Money\PayoutService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCA\Dossiq\Tests\Unit\Service\FakeTermijnStore;
use OCA\Shillinq\Event\PayableRaisedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Money\PayoutService
 * @uses \OCA\Dossiq\Service\Money\PayoutRequester
 * @uses \OCA\Dossiq\Service\Money\PayoutRequest
 * @uses \OCA\Dossiq\Service\Money\MoneyStore
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class PayoutServiceRaiseAgainTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The object store.
	 *
	 * @var FakeTermijnStore
	 */
	private FakeTermijnStore $objects;

	/**
	 * Settings answering the money schemas and the store.
	 *
	 * @var SettingsService
	 */
	private SettingsService $settings;

	/**
	 * Set up: one settled calculation.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objects = new FakeTermijnStore();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return match ($key) {
					'register' => 'dossiq',
					'accruing_amount_schema' => 'penaltyPaymentCalculation',
					'payout_schema' => 'dwangsomUitbetaling',
					default => '',
				};
			},
		);
		$this->settings = $settings;

		$this->objects->seed('penaltyPaymentCalculation', ['id' => 'b-1', 'status' => 'gestopt-wegens-decision', 'definitiveAmount' => 35700]);
	}//end setUp()

	/**
	 * A service whose finance app answers with the given payable id, or stays silent.
	 *
	 * @param string|null        $payableId The payable id finance accepts with, or null for no answer.
	 * @param array<int, object> $seen      The commands finance received.
	 *
	 * @return PayoutService The service.
	 */
	private function service(?string $payableId, array &$seen = []): PayoutService {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (object $event) use ($payableId, &$seen): void {
				$seen[] = $event;
				if ($payableId !== null) {
					$event->accept($payableId, 'requested');
				}
			}
		);

		return new PayoutService(settingsService: $this->settings, dates: $this->caseDates(), payouts: new PayoutRequester(dispatcher: $dispatcher));
	}//end service()

	/**
	 * A payout row out of finance with an amount left.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function row(): array {
		return [
			'id' => 'u-1',
			'penaltyPaymentCalculation' => 'b-1',
			'amount' => 30000,
			'accountHolderName' => 'J. Burger',
			'iban' => 'NL91ABNA0417164300',
			'reference' => 'PROC-DWS-OLD',
			'payableId' => '',
			'legalBasis' => 'AWB 4:17',
			'status' => 'voorbereid',
		];
	}//end row()

	/**
	 * Request input becomes a payout: names and IBAN from the input, the
	 * deadline from the receipt date.
	 *
	 * @return void
	 */
	public function testPreparePayoutFromReadsTheInput(): void {
		$row = (new PayoutService(settingsService: $this->settings, dates: $this->caseDates()))->preparePayoutFrom(
			calculationId: 'b-1',
			input: ['accountHolderName' => 'J. Burger', 'iban' => 'NL91 ABNA 0417 1643 00', 'receiptDate' => '2026-04-01'],
		);

		self::assertSame(35700, $row['amount']);
		self::assertSame('J. Burger', $row['accountHolderName']);
		self::assertSame('NL91ABNA0417164300', $row['iban']);
		self::assertSame('2026-04-29', $row['paymentDateLatest']);
		self::assertSame('voorbereid', $row['status']);
	}//end testPreparePayoutFromReadsTheInput()

	/**
	 * A receipt date this system cannot read is refused naming the field.
	 *
	 * @return void
	 */
	public function testPreparePayoutFromRefusesAnUnreadableReceiptDate(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('receiptDate is not a date this system can read');

		(new PayoutService(settingsService: $this->settings, dates: $this->caseDates()))->preparePayoutFrom(
			calculationId: 'b-1',
			input: ['accountHolderName' => 'J. Burger', 'iban' => 'NL91ABNA0417164300', 'receiptDate' => 'next tuesday-ish'],
		);
	}//end testPreparePayoutFromRefusesAnUnreadableReceiptDate()

	/**
	 * A row still in finance, or with nothing to pay, is not raised again.
	 *
	 * @return void
	 */
	public function testARowInFinanceOrWithoutAmountIsNotRaisedAgain(): void {
		$seen = [];
		$service = $this->service(payableId: 'p-9', seen: $seen);

		$inFinance = ['payableId' => 'p-1'] + $this->row();
		$nothing = ['amount' => 0] + $this->row();

		self::assertSame($inFinance, $service->raiseAgain(row: $inFinance));
		self::assertSame($nothing, $service->raiseAgain(row: $nothing));
		self::assertSame([], $seen);
	}//end testARowInFinanceOrWithoutAmountIsNotRaisedAgain()

	/**
	 * Without a finance answer the row keeps its old reference and no payable.
	 *
	 * @return void
	 */
	public function testWithoutAnAnswerTheRowKeepsItsReference(): void {
		$seen = [];

		$row = $this->service(payableId: null, seen: $seen)->raiseAgain(row: $this->row());

		self::assertSame($this->row(), $row);
		self::assertInstanceOf(PayableRaisedEvent::class, $seen[0]);
		self::assertNotSame('PROC-DWS-OLD', $seen[0]->toArray()['reference']);
	}//end testWithoutAnAnswerTheRowKeepsItsReference()

	/**
	 * An accepted raise carries a new reference and finance's payable id.
	 *
	 * @return void
	 */
	public function testAnAcceptedRaiseCarriesANewReferenceAndThePayable(): void {
		$seen = [];

		$row = $this->service(payableId: 'p-9', seen: $seen)->raiseAgain(row: $this->row());

		self::assertSame('p-9', $row['payableId']);
		self::assertMatchesRegularExpression('/^PROC-DWS-[0-9A-F]{12}$/', $row['reference']);
		self::assertSame($row['reference'], $seen[0]->toArray()['reference']);
		self::assertSame(300.0, $seen[0]->toArray()['amount']);
	}//end testAnAcceptedRaiseCarriesANewReferenceAndThePayable()
}//end class
