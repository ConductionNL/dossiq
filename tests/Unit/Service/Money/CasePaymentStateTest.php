<?php

/**
 * What the case says about the money, and what it must never say.
 *
 * Three of these assertions exist because the failure they catch is silent.
 *
 * A READ THAT FAILED AND A CASE THAT OWES NOTHING ARE OPPOSITE FACTS. Both
 * would render as a case with no payment problem, and only one of them is
 * true, so `stale` is asserted as its own answer in every path that can
 * produce it: no shillinq, a throwing leaf, an envelope this app cannot read.
 *
 * THE PROJECTION MUST CARRY NO MONEY. dossiq holding "paid" and shillinq
 * holding "€162,50 received on the 4th" is one fact in two places, and the day
 * they disagree the citizen is right and both systems are wrong. The shape of
 * the projection is therefore asserted key for key, not just for the state it
 * carries.
 *
 * ZGW IS DERIVED AND NEVER SET BESIDE THIS. `paymentIndication` is on the case
 * for the ZRC API. A `stale` state maps to NOTHING, because writing `nvt`
 * there would have the ZRC field claim a fact this app has just said it could
 * not read.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Money
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/fees-and-payments-on-the-case/specs/financial-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Money;

use OCA\Dossiq\Service\Money\CasePaymentState;
use PHPUnit\Framework\TestCase;

/**
 * The case-level payment state.
 *
 * @spec openspec/changes/fees-and-payments-on-the-case/specs/financial-integration/spec.md
 */
class CasePaymentStateTest extends TestCase {
	private CasePaymentState $states;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->states = new CasePaymentState();
	}//end setUp()

	/**
	 * One leaf item, as shillinq's payment leaf reports it.
	 *
	 * @param string $reported The state shillinq derived.
	 * @param array<int, array<string, mixed>> $settlements The settlements on it.
	 *
	 * @return array<string, mixed> The item.
	 */
	private function request(string $reported, array $settlements = []): array {
		return [
			'id' => 'req-1',
			'amount' => 162.5,
			'reported' => ['state' => $reported, 'settled' => 0.0, 'due' => 162.5, 'over' => 0.0],
			'settlements' => $settlements,
		];
	}//end request()

	/**
	 * A case nobody raised a request on owes nothing.
	 *
	 * @return void
	 */
	public function testNoRequestsMeansNothingIsOwed(): void {
		$this->assertSame(CasePaymentState::NOT_REQUIRED, $this->states->fromRequests([]));
	}//end testNoRequestsMeansNothingIsOwed()

	/**
	 * Anything unsettled outranks everything else.
	 *
	 * @return void
	 */
	public function testOneOpenRequestMakesTheWholeCaseOutstanding(): void {
		$requests = [$this->request('paid'), $this->request('open')];

		$this->assertSame(CasePaymentState::OUTSTANDING, $this->states->fromRequests($requests));
	}//end testOneOpenRequestMakesTheWholeCaseOutstanding()

	/**
	 * A part payment is not a payment.
	 *
	 * @return void
	 */
	public function testAPartPaymentIsStillOutstanding(): void {
		$this->assertSame(CasePaymentState::OUTSTANDING, $this->states->fromRequests([$this->request('partly-paid')]));
	}//end testAPartPaymentIsStillOutstanding()

	/**
	 * A request the provider gave up on is money still owed, not money gone.
	 *
	 * @return void
	 */
	public function testAnUnpayableRequestIsOutstanding(): void {
		$this->assertSame(CasePaymentState::OUTSTANDING, $this->states->fromRequests([$this->request('unpayable')]));
	}//end testAnUnpayableRequestIsOutstanding()

	/**
	 * Somebody paying twice is shillinq's problem to refund, not a reason to
	 * hold the case.
	 *
	 * @return void
	 */
	public function testAnOverpaymentCountsAsSettledHere(): void {
		$this->assertSame(CasePaymentState::PAID, $this->states->fromRequests([$this->request('overpaid')]));
	}//end testAnOverpaymentCountsAsSettledHere()

	/**
	 * A waiver is its own word, because letting the money go is a decision
	 * somebody made and not the same fact as being paid.
	 *
	 * @return void
	 */
	public function testAWaivedRequestReadsWaivedAndNotPaid(): void {
		$waived = $this->request('paid', [['method' => 'waived', 'reason' => 'kwijtschelding']]);

		$this->assertSame(CasePaymentState::WAIVED, $this->states->fromRequests([$waived]));
	}//end testAWaivedRequestReadsWaivedAndNotPaid()

	/**
	 * Money that did arrive is not hidden behind a waiver beside it.
	 *
	 * @return void
	 */
	public function testAWaiverBesideARealPaymentStillReadsPaid(): void {
		$waived = $this->request('paid', [['method' => 'waived', 'reason' => 'kwijtschelding']]);
		$cash = $this->request('paid', [['method' => 'cash', 'settlementReference' => 'kas-4412']]);

		$this->assertSame(CasePaymentState::PAID, $this->states->fromRequests([$waived, $cash]));
	}//end testAWaiverBesideARealPaymentStillReadsPaid()

	/**
	 * An item with no reported block is a shape this app cannot read, which is
	 * not the same as a settled one.
	 *
	 * @return void
	 */
	public function testARequestWithNoReportedStateIsOutstanding(): void {
		$this->assertSame(CasePaymentState::OUTSTANDING, $this->states->fromRequests([['id' => 'req-9']]));
	}//end testARequestWithNoReportedStateIsOutstanding()

	/**
	 * `stale` is never an answer the derivation gives; it is what a failed read
	 * means.
	 *
	 * @return void
	 */
	public function testAReadableAnswerNeverReadsStale(): void {
		// Narrower than it used to claim. `stale` IS an answer now, for the one
		// case where shillinq says it could not do the sum. What it still may
		// never be is the answer to a request this app could read.
		foreach ([[], [$this->request('open')], [$this->request('paid')]] as $requests) {
			$this->assertNotSame(CasePaymentState::STALE, $this->states->fromRequests($requests));
		}

		$this->assertFalse($this->states->isKnown(CasePaymentState::STALE));
		$this->assertTrue($this->states->isKnown(CasePaymentState::OUTSTANDING));
		$this->assertFalse($this->states->isKnown(''));
	}//end testAReadableAnswerNeverReadsStale()

	/**
	 * The projection carries a state and a timestamp, and no money at all.
	 *
	 * @return void
	 */
	public function testTheProjectionHoldsNoAmountNoLedgerLineAndNoPaymentDate(): void {
		$projection = $this->states->projection(state: CasePaymentState::PAID, checkedAt: '2026-09-18T10:00:00+02:00');

		$this->assertSame(['paymentState', 'paymentStateCheckedAt'], array_keys($projection));
		$this->assertSame(CasePaymentState::PAID, $projection['paymentState']);
		$this->assertSame('2026-09-18T10:00:00+02:00', $projection['paymentStateCheckedAt']);
	}//end testTheProjectionHoldsNoAmountNoLedgerLineAndNoPaymentDate()

	/**
	 * A word this app does not know falls to stale rather than being written
	 * through onto the case.
	 *
	 * @return void
	 */
	public function testAnUnknownStateIsWrittenAsStale(): void {
		$projection = $this->states->projection(state: 'settled-ish', checkedAt: 'now');

		$this->assertSame(CasePaymentState::STALE, $projection['paymentState']);
	}//end testAnUnknownStateIsWrittenAsStale()

	/**
	 * The ZGW field is derived, and says nothing where this app knows nothing.
	 *
	 * @return void
	 */
	public function testTheZgwIndicationIsDerivedAndSilentOnStale(): void {
		$this->assertSame('nvt', $this->states->zgwIndication(CasePaymentState::NOT_REQUIRED));
		$this->assertSame('nvt', $this->states->zgwIndication(CasePaymentState::WAIVED));
		$this->assertSame('not_yet', $this->states->zgwIndication(CasePaymentState::OUTSTANDING));
		$this->assertSame('geheel', $this->states->zgwIndication(CasePaymentState::PAID));
		$this->assertNull($this->states->zgwIndication(CasePaymentState::STALE));
	}//end testTheZgwIndicationIsDerivedAndSilentOnStale()

	/**
	 * shillinq answers `indeterminate` when a request's own amount cannot be
	 * read as a number (shillinq#1641). That is not money owed. It read as
	 * `outstanding`, which sends a handler to chase a payment that may already
	 * have been made and tells a citizen with the receipt they still owe us.
	 *
	 * @return void
	 */
	public function testAnIndeterminateRequestReadsStaleAndNotOutstanding(): void {
		$state = $this->states->fromRequests([$this->request('indeterminate')]);

		$this->assertSame(CasePaymentState::STALE, $state);
		$this->assertNotSame(CasePaymentState::OUTSTANDING, $state);
	}//end testAnIndeterminateRequestReadsStaleAndNotOutstanding()

	/**
	 * And it is not readable either, so the gate refuses rather than allows.
	 * `stale` has to stay outside `isKnown()` for that to hold.
	 *
	 * @return void
	 */
	public function testAnIndeterminateRequestIsNotAKnownState(): void {
		$this->assertFalse(
			$this->states->isKnown($this->states->fromRequests([$this->request('indeterminate')]))
		);
	}//end testAnIndeterminateRequestIsNotAKnownState()

	/**
	 * A request this app cannot parse at all was SKIPPED, so a case whose only
	 * request was malformed fell through to the waiver branch and read
	 * `waived`. That opens the gate and says a person decided to let the money
	 * go. Nobody decided anything.
	 *
	 * @return void
	 */
	public function testAnUnparseableRequestDoesNotReadAsAWaiver(): void {
		$state = $this->states->fromRequests(['not-an-array']);

		$this->assertSame(CasePaymentState::STALE, $state);
		$this->assertNotSame(CasePaymentState::WAIVED, $state);
	}//end testAnUnparseableRequestDoesNotReadAsAWaiver()

	/**
	 * A request nobody has paid is a fact that holds whatever the row beside it
	 * says, and it is the one a handler can act on. So outstanding outranks
	 * unreadable, in either order: the answer must not depend on the order the
	 * leaf happened to send.
	 *
	 * @return void
	 */
	public function testAnOutstandingRequestOutranksAnUnreadableOneInEitherOrder(): void {
		$this->assertSame(
			CasePaymentState::OUTSTANDING,
			$this->states->fromRequests([$this->request('indeterminate'), $this->request('open')])
		);
		$this->assertSame(
			CasePaymentState::OUTSTANDING,
			$this->states->fromRequests([$this->request('open'), $this->request('indeterminate')])
		);
	}//end testAnOutstandingRequestOutranksAnUnreadableOneInEitherOrder()

	/**
	 * A case is not paid while part of its record is unreadable, in either
	 * order. Calling it paid is the answer that ends the chase.
	 *
	 * @return void
	 */
	public function testAnUnreadableRequestBesideAPaidOneIsNotPaid(): void {
		$this->assertSame(
			CasePaymentState::STALE,
			$this->states->fromRequests([$this->request('paid'), $this->request('indeterminate')])
		);
		$this->assertSame(
			CasePaymentState::STALE,
			$this->states->fromRequests([$this->request('indeterminate'), $this->request('paid')])
		);
	}//end testAnUnreadableRequestBesideAPaidOneIsNotPaid()
}//end class
