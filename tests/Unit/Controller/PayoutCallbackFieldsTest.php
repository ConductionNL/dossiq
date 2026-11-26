<?php

/**
 * PayoutCallbackFields Unit Tests
 *
 * What a payout callback says: the signed reader's fields, with the actual
 * payment date parsed through the one date write path, null when the body
 * names none, and a 400 with the reason when the body or the date is wrong.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Dossiq\Controller\PayoutCallbackFields;
use OCA\Dossiq\Kernel\Contracts\CaseDateNormaliserInterface;
use OCA\Dossiq\Kernel\Contracts\SignedPayoutCallbackInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PayoutCallbackFields.
 *
 * @covers \OCA\Dossiq\Controller\PayoutCallbackFields
 */
final class PayoutCallbackFieldsTest extends TestCase {

	/**
	 * A reader that answers these fields for any body.
	 *
	 * @param string $paymentDate The actual payment date the body carries.
	 *
	 * @return SignedPayoutCallbackInterface
	 */
	private function reader(string $paymentDate): SignedPayoutCallbackInterface {
		$reader = $this->createMock(originalClassName: SignedPayoutCallbackInterface::class);
		$reader->method('read')->with('{"body":1}')->willReturn(
			['reference' => 'REF-1', 'status' => 'betaald', 'actualPaymentDate' => $paymentDate, 'bankReference' => 'B-9', 'payableId' => '']
		);
		return $reader;
	}//end reader()

	/**
	 * The payment date is parsed through the write path, naming its field.
	 *
	 * @return void
	 */
	public function testThePaymentDateIsParsedThroughTheWritePath(): void {
		$paid = new DateTimeImmutable('2026-11-20');
		$dates = $this->createMock(originalClassName: CaseDateNormaliserInterface::class);
		$dates->expects($this->once())->method('parse')->with('2026-11-20', 'actualPaymentDate')->willReturn($paid);

		$fields = (new PayoutCallbackFields(callback: $this->reader(paymentDate: '2026-11-20'), dates: $dates))->read(rawBody: '{"body":1}');

		$this->assertSame(expected: $paid, actual: $fields['paymentDate']);
		$this->assertSame(expected: 'REF-1', actual: $fields['reference']);
		$this->assertSame(expected: 'B-9', actual: $fields['bankReference']);
	}//end testThePaymentDateIsParsedThroughTheWritePath()

	/**
	 * Without a payment date nothing is parsed and the date is null.
	 *
	 * @return void
	 */
	public function testNoPaymentDateIsNull(): void {
		$dates = $this->createMock(originalClassName: CaseDateNormaliserInterface::class);
		$dates->expects($this->never())->method('parse');

		$fields = (new PayoutCallbackFields(callback: $this->reader(paymentDate: ''), dates: $dates))->read(rawBody: '{"body":1}');

		$this->assertNull(actual: $fields['paymentDate']);
	}//end testNoPaymentDateIsNull()

	/**
	 * A malformed date, or a body the reader refuses, is a 400 with the reason.
	 *
	 * @return void
	 */
	public function testAMalformedDateOrBodyIsA400(): void {
		$dates = $this->createMock(originalClassName: CaseDateNormaliserInterface::class);
		$dates->method('parse')->willThrowException(new InvalidArgumentException(message: 'actualPaymentDate is not a date'));
		$badDate = (new PayoutCallbackFields(callback: $this->reader(paymentDate: 'gisteren'), dates: $dates))->read(rawBody: '{"body":1}');

		$this->assertInstanceOf(expected: JSONResponse::class, actual: $badDate);
		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $badDate->getStatus());
		$this->assertSame(expected: ['message' => 'actualPaymentDate is not a date'], actual: $badDate->getData());

		$reader = $this->createMock(originalClassName: SignedPayoutCallbackInterface::class);
		$reader->method('read')->willThrowException(new InvalidArgumentException(message: 'Invalid JSON body'));
		$badBody = (new PayoutCallbackFields(callback: $reader, dates: $dates))->read(rawBody: 'not json');

		$this->assertSame(expected: ['message' => 'Invalid JSON body'], actual: $badBody->getData());
	}//end testAMalformedDateOrBodyIsA400()
}//end class
