<?php

/**
 * PayoutReader row-shape tests.
 *
 * What finance answers, read back in the row shape dossiq kept: the state
 * mapping, the cents conversion, the rows finance does not hold, and the
 * reference lookup.
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

use OCA\Dossiq\Service\Money\PayoutReader;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Money\PayoutReader
 */
class PayoutReaderRowsTest extends TestCase {

	/**
	 * A reader whose finance app answers with the given payouts, or stays silent.
	 *
	 * @param array<int, array<string, mixed>>|null $payouts The payouts, or null for no answer.
	 * @param array<int, object>                    $seen    The events dispatched.
	 *
	 * @return PayoutReader The reader.
	 */
	private function reader(?array $payouts, array &$seen = []): PayoutReader {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (object $event) use ($payouts, &$seen): void {
				$seen[] = $event;
				if ($payouts !== null) {
					$event->answer($payouts);
				}
			}
		);

		return new PayoutReader(dispatcher: $dispatcher);
	}//end reader()

	/**
	 * One payout as finance reports it.
	 *
	 * @param string $state     The finance state.
	 * @param string $payableId The payable id.
	 *
	 * @return array<string, mixed> The payout.
	 */
	private function payout(string $state, string $payableId = 'p-1'): array {
		return [
			'payableId' => $payableId,
			'correlationId' => 'calc-1',
			'reference' => 'PROC-DWS-' . $payableId,
			'amount' => 162.505,
			'state' => $state,
			'paidOn' => '2026-04-20',
			'bankReference' => 'ERP-1',
			'payee' => ['name' => 'J. Burger', 'iban' => 'NL91ABNA0417164300'],
		];
	}//end payout()

	/**
	 * Without a finance answer the stored rows are the truth, untouched.
	 *
	 * @return void
	 */
	public function testWithoutAnAnswerTheStoredRowsAreReturnedAsIs(): void {
		$stored = [['id' => 'u-1', 'payableId' => 'p-1', 'status' => 'paid'], ['id' => 'u-2', 'status' => 'voorbereid']];
		$seen = [];

		self::assertSame($stored, $this->reader(payouts: null, seen: $seen)->mergedRows(storedRows: $stored, paidInYear: 2025));
		self::assertCount(1, $seen);
		self::assertSame(2025, $seen[0]->getPaidInYear());
	}//end testWithoutAnAnswerTheStoredRowsAreReturnedAsIs()

	/**
	 * Every finance payout becomes one row in dossiq's shape, with euros in cents.
	 *
	 * @return void
	 */
	public function testAFinancePayoutBecomesARowInDossiqsShape(): void {
		$rows = $this->reader(payouts: [$this->payout(state: 'approved')])->mergedRows(storedRows: []);

		self::assertSame(
			[
				[
					'id' => 'p-1',
					'payableId' => 'p-1',
					'penaltyPaymentCalculation' => 'calc-1',
					'reference' => 'PROC-DWS-p-1',
					'amount' => 16251,
					'status' => 'in-handling',
					'actualPaymentDate' => '2026-04-20',
					'betalingsreferentie' => 'ERP-1',
					'accountHolderName' => 'J. Burger',
					'iban' => 'NL91ABNA0417164300',
					'legalBasis' => 'AWB 4:17',
				],
			],
			$rows
		);
	}//end testAFinancePayoutBecomesARowInDossiqsShape()

	/**
	 * Each known finance state maps to dossiq's status; an unknown state is no row.
	 *
	 * @return void
	 */
	public function testFinanceStatesMapAndUnknownStatesAreDropped(): void {
		$payouts = [
			$this->payout(state: 'requested', payableId: 'a'),
			$this->payout(state: 'paid', payableId: 'b'),
			$this->payout(state: 'rejected', payableId: 'c'),
			$this->payout(state: 'cancelled', payableId: 'd'),
			['payableId' => 'e'],
		];

		$rows = $this->reader(payouts: $payouts)->mergedRows(storedRows: []);

		self::assertSame(['a' => 'voorbereid', 'b' => 'paid', 'c' => 'rejected'], array_column($rows, 'status', 'payableId'));
	}//end testFinanceStatesMapAndUnknownStatesAreDropped()

	/**
	 * A stored row that never reached finance is kept beside finance's rows;
	 * one that did is replaced by finance's version.
	 *
	 * @return void
	 */
	public function testStoredRowsFinanceDoesNotHoldAreAppended(): void {
		$stored = [
			['id' => 'u-1', 'payableId' => 'p-1', 'status' => 'voorbereid'],
			['id' => 'u-2', 'payableId' => '', 'status' => 'voorbereid'],
			['id' => 'u-3', 'status' => 'on-hold-objection'],
		];

		$rows = $this->reader(payouts: [$this->payout(state: 'paid')])->mergedRows(storedRows: $stored);

		self::assertCount(3, $rows);
		self::assertSame('p-1', $rows[0]['id']);
		self::assertSame('paid', $rows[0]['status']);
		self::assertSame($stored[1], $rows[1]);
		self::assertSame($stored[2], $rows[2]);
	}//end testStoredRowsFinanceDoesNotHoldAreAppended()

	/**
	 * The rows of one calculation are asked for that calculation only, and a
	 * state finance no longer reports as live reads as withdrawn.
	 *
	 * @return void
	 */
	public function testRowsForACalculationReadAnUnknownStateAsWithdrawn(): void {
		$seen = [];
		$payout = $this->payout(state: 'cancelled');
		unset($payout['payee']);

		$rows = $this->reader(payouts: [$payout, $this->payout(state: 'requested', payableId: 'p-2')], seen: $seen)->rowsForCalculation(calculationId: 'calc-1');

		self::assertSame(['calc-1'], $seen[0]->getCorrelationIds());
		self::assertSame(['withdrawn', 'voorbereid'], array_column($rows, 'status'));
		self::assertSame('', $rows[0]['accountHolderName']);
		self::assertSame('', $rows[0]['iban']);
	}//end testRowsForACalculationReadAnUnknownStateAsWithdrawn()

	/**
	 * No answer for a calculation is no rows, not an error.
	 *
	 * @return void
	 */
	public function testRowsForACalculationWithoutAnAnswerAreEmpty(): void {
		self::assertSame([], $this->reader(payouts: null)->rowsForCalculation(calculationId: 'calc-1'));
	}//end testRowsForACalculationWithoutAnAnswerAreEmpty()

	/**
	 * Finance holds a reference only when one of its payouts carries it exactly.
	 *
	 * @return void
	 */
	public function testHoldsMatchesTheReferenceExactly(): void {
		$reader = $this->reader(payouts: [$this->payout(state: 'paid')]);

		self::assertTrue($reader->holds(reference: 'PROC-DWS-p-1'));
		self::assertFalse($reader->holds(reference: 'PROC-DWS-p'));
		self::assertFalse($this->reader(payouts: null)->holds(reference: 'PROC-DWS-p-1'));
	}//end testHoldsMatchesTheReferenceExactly()
}//end class
