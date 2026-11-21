<?php

/**
 * PayoutRequest unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Money
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Money;

use OCA\Dossiq\Service\Money\PayoutRequest;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * What a payout request checks of itself: its IBAN and its account holder.
 *
 * @covers \OCA\Dossiq\Service\Money\PayoutRequest
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class PayoutRequestTest extends TestCase {

	/**
	 * A request for this account and holder.
	 *
	 * @param string $iban   The account.
	 * @param string $holder The account holder.
	 *
	 * @return PayoutRequest
	 */
	private function request(string $iban, string $holder = 'J. Jansen'): PayoutRequest {
		$request = new PayoutRequest();
		$request->iban = $iban;
		$request->accountHolderName = $holder;

		return $request;
	}//end request()

	/**
	 * A valid IBAN passes with or without spaces; a wrong check digit or shape does not.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testTheIbanIsCheckedByLengthAndModNinetySeven(): void {
		$this->assertTrue(condition: $this->request(iban: 'NL91ABNA0417164300')->hasValidIban());
		$this->assertTrue(condition: $this->request(iban: 'nl91 abna 0417 1643 00')->hasValidIban());
		$this->assertFalse(condition: $this->request(iban: 'NL91ABNA0417164301')->hasValidIban());
		$this->assertFalse(condition: $this->request(iban: 'NL91')->hasValidIban());
	}//end testTheIbanIsCheckedByLengthAndModNinetySeven()

	/**
	 * An invalid IBAN is refused before a blank holder; a valid request passes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testAnUnpayableRequestIsRefusedWithItsReason(): void {
		$this->request(iban: 'NL91ABNA0417164300')->assertPayable();

		try {
			$this->request(iban: 'not-an-iban', holder: ' ')->assertPayable();
			$this->fail(message: 'An invalid IBAN was accepted.');
		} catch (RuntimeException $e) {
			$this->assertSame(expected: 'Invalid IBAN provided for dwangsom uitbetaling', actual: $e->getMessage());
		}

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'rekeninghouderNaam is required');
		$this->request(iban: 'NL91ABNA0417164300', holder: ' ')->assertPayable();
	}//end testAnUnpayableRequestIsRefusedWithItsReason()
}//end class
