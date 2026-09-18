<?php

/**
 * Whether a case that has not been paid for may be worked on.
 *
 * The assertion that matters most is the one about the service being down.
 * Letting the act through because the money app could not be asked is a
 * fail-open: it looks exactly like a case that was paid, it happens on the one
 * afternoon shillinq is restarting, and nothing anywhere records that it
 * happened. ADR-102 says the opposite, and this is where that is held.
 *
 * The second is the default. Most case types cost nothing, and a rule that
 * read an absent declaration as "wait for money" would stop every melding on
 * every install the hour this shipped.
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
use OCA\Dossiq\Service\Money\UnpaidCaseGate;
use PHPUnit\Framework\TestCase;

/**
 * The case type's payment rule, applied to one case.
 *
 * @spec openspec/changes/fees-and-payments-on-the-case/specs/financial-integration/spec.md
 */
class UnpaidCaseGateTest extends TestCase {
	private UnpaidCaseGate $gate;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->gate = new UnpaidCaseGate(new CasePaymentState());
	}//end setUp()

	/**
	 * A melding does not wait for money.
	 *
	 * @return void
	 */
	public function testACaseTypeWithNoRuleNeverWaits(): void {
		$this->assertSame(
			'',
			$this->gate->whyItWaits(
				case: ['paymentState' => CasePaymentState::OUTSTANDING],
				caseType: ['paymentRequiredBeforeHandling' => false],
			)
		);
	}//end testACaseTypeWithNoRuleNeverWaits()

	/**
	 * An absent declaration means no, so nothing on an existing install starts
	 * waiting for money the day this ships.
	 *
	 * @return void
	 */
	public function testAnAbsentDeclarationMeansNo(): void {
		$this->assertFalse($this->gate->requiresPayment(caseType: []));
		$this->assertFalse($this->gate->requiresPayment(caseType: null));
		$this->assertTrue($this->gate->requiresPayment(caseType: ['paymentRequiredBeforeHandling' => true]));

		$this->assertSame(
			'',
			$this->gate->whyItWaits(case: ['paymentState' => CasePaymentState::OUTSTANDING], caseType: null)
		);
	}//end testAnAbsentDeclarationMeansNo()

	/**
	 * An unpaid aanvraag waits, and the refusal names the rule rather than
	 * sending the handler to the rights matrix.
	 *
	 * @return void
	 */
	public function testAnOutstandingPaymentRefusesAndNamesTheRule(): void {
		$sentence = $this->gate->whyItWaits(
			case: ['paymentState' => CasePaymentState::OUTSTANDING],
			caseType: ['paymentRequiredBeforeHandling' => true],
		);

		$this->assertStringContainsString('settled first', $sentence);
		$this->assertStringContainsString('outstanding', $sentence);
	}//end testAnOutstandingPaymentRefusesAndNamesTheRule()

	/**
	 * Paid and waived both let the case through: in one the money arrived, in
	 * the other somebody decided it need not.
	 *
	 * @return void
	 */
	public function testASettledCaseProceeds(): void {
		foreach ([CasePaymentState::PAID, CasePaymentState::WAIVED, CasePaymentState::NOT_REQUIRED] as $state) {
			$this->assertSame(
				'',
				$this->gate->whyItWaits(case: ['paymentState' => $state], caseType: ['paymentRequiredBeforeHandling' => true]),
				sprintf('a case reading %s should proceed', $state)
			);
		}
	}//end testASettledCaseProceeds()

	/**
	 * ADR-102: an unreadable state closes the gate, and says which service went
	 * quiet rather than accusing the citizen.
	 *
	 * @return void
	 */
	public function testAnUnreadableStateRefusesAndSaysTheServiceIsUnavailable(): void {
		$sentence = $this->gate->whyItWaits(
			case: ['paymentState' => CasePaymentState::STALE],
			caseType: ['paymentRequiredBeforeHandling' => true],
		);

		$this->assertStringContainsString('could not be reached', $sentence);
		$this->assertStringNotContainsString('outstanding', $sentence);
	}//end testAnUnreadableStateRefusesAndSaysTheServiceIsUnavailable()

	/**
	 * A case that carries no state at all has never been read, which is the
	 * same ignorance as a failed read and is refused the same way.
	 *
	 * @return void
	 */
	public function testACaseWithNoStateAtAllIsTreatedAsUnreadable(): void {
		$sentence = $this->gate->whyItWaits(case: [], caseType: ['paymentRequiredBeforeHandling' => true]);

		$this->assertStringContainsString('could not be reached', $sentence);
	}//end testACaseWithNoStateAtAllIsTreatedAsUnreadable()
}//end class
