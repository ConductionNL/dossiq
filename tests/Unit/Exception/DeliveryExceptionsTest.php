<?php

/**
 * Unit tests for the delivery refusals: a notice that was not sent, a
 * recipient who opted out and a forfeit invoice that was not raised each keep
 * their reason code, and say so in a default sentence when no reason is given.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Exception;

use OCA\Dossiq\Exception\ForfeitInvoiceNotRaisedException;
use OCA\Dossiq\Exception\NoticeNotSentException;
use OCA\Dossiq\Exception\RecipientOptedOutException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Exception\NoticeNotSentException
 * @covers \OCA\Dossiq\Exception\RecipientOptedOutException
 * @covers \OCA\Dossiq\Exception\ForfeitInvoiceNotRaisedException
 */
class DeliveryExceptionsTest extends TestCase {

	public function testANoticeNotSentKeepsItsReasonAndDelivery(): void {
		$notice = new NoticeNotSentException(reasonCode: 'no-channel', reason: 'No address is known.', delivery: ['channelsTried' => ['email']]);

		$this->assertSame('no-channel', $notice->getReasonCode());
		$this->assertSame('No address is known.', $notice->getMessage());
		$this->assertSame(['channelsTried' => ['email']], $notice->getDelivery());
	}//end testANoticeNotSentKeepsItsReasonAndDelivery()

	public function testANoticeWithoutAReasonSaysItWasNotSent(): void {
		$notice = new NoticeNotSentException(reasonCode: 'transport-down');

		$this->assertSame('The notice was not sent.', $notice->getMessage());
		$this->assertSame([], $notice->getDelivery());
	}//end testANoticeWithoutAReasonSaysItWasNotSent()

	public function testAnOptOutSaysNothingWasSentUnlessGivenAReason(): void {
		$this->assertSame('The recipient may not be sent this message. Nothing was sent.', (new RecipientOptedOutException(reasonCode: 'opted-out'))->getMessage());

		$optOut = new RecipientOptedOutException(reasonCode: 'opted-out', reason: 'Dhr. Jansen ontvangt alleen post.');
		$this->assertSame('opted-out', $optOut->getReasonCode());
		$this->assertSame('Dhr. Jansen ontvangt alleen post.', $optOut->getMessage());
	}//end testAnOptOutSaysNothingWasSentUnlessGivenAReason()

	public function testAForfeitInvoiceNotRaisedNamesItsReasonAndCase(): void {
		$cause = new RuntimeException('finance down');
		$invoice = new ForfeitInvoiceNotRaisedException(reasonCode: ForfeitInvoiceNotRaisedException::FINANCE_REFUSED, caseId: 'case-1', previous: $cause);

		$this->assertSame('No invoice was raised for the forfeited penalty: finance-refused', $invoice->getMessage());
		$this->assertSame('finance-refused', $invoice->getReasonCode());
		$this->assertSame('case-1', $invoice->getCaseId());
		$this->assertSame($cause, $invoice->getPrevious());
	}//end testAForfeitInvoiceNotRaisedNamesItsReasonAndCase()
}//end class
