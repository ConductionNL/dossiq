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

use OCA\Dossiq\Service\Money\PayoutRequester;
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
