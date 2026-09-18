<?php

/**
 * Whether a case that has not been paid for may be worked on.
 *
 * 🔑 THE CASE TYPE DECIDES, NOT THIS CLASS (D-4). A vergunningaanvraag waits
 * for its leges; a melding openbare ruimte does not, because a pothole nobody
 * fixed is a worse outcome than a fee nobody collected. Both are policy a
 * gemeente sets per case type, so the only question here is what the
 * declaration means once it is made.
 *
 * 🔴 AN UNREADABLE STATE CLOSES THE GATE, IT DOES NOT OPEN IT (ADR-102). Where
 * a case type requires payment and shillinq cannot be asked, the act is
 * refused and the refusal says the payment service could not be reached. The
 * opposite reading — let it through, shillinq was probably fine — is how an
 * unpaid case gets handled on the one afternoon the money app was down, and
 * nothing anywhere records that it happened.
 *
 * 🔴 THE REFUSAL NAMES THE RULE (ADR-050). "Not allowed" sends a handler to
 * the rights matrix for an afternoon. "This case type requires the leges to be
 * settled first, and they are outstanding" sends them to the payment panel,
 * which is where the answer is.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Money
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
 * @spec openspec/changes/fees-and-payments-on-the-case/specs/financial-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Money;

/**
 * Reads a case type's payment rule, and says what it means for an act.
 *
 * @spec openspec/changes/fees-and-payments-on-the-case/specs/financial-integration/spec.md
 */
class UnpaidCaseGate {
	/**
	 * The case-type field declaring that payment comes first.
	 *
	 * @var string
	 */
	public const REQUIRES_PAYMENT = 'paymentRequiredBeforeHandling';

	/**
	 * The case field carrying the projection.
	 *
	 * @var string
	 */
	public const CASE_STATE = 'paymentState';

	/**
	 * Constructor.
	 *
	 * @param CasePaymentState $states The state vocabulary.
	 */
	public function __construct(private readonly CasePaymentState $states) {
	}//end __construct()

	/**
	 * Whether this case type makes payment a precondition for handling.
	 *
	 * ABSENT MEANS NO, deliberately. Most case types cost nothing, and a rule
	 * that defaulted to "wait for money" would stop every melding on every
	 * install the moment this shipped.
	 *
	 * @param array<string, mixed>|null $caseType The case type, as stored.
	 *
	 * @return bool True when an unpaid case waits.
	 *
	 * @spec openspec/changes/fees-and-payments-on-the-case/specs/financial-integration/spec.md#requirement-a-case-type-decides-whether-an-unpaid-case-proceeds-req-fee-04
	 */
	public function requiresPayment(?array $caseType): bool {
		if ($caseType === null) {
			return false;
		}

		return (($caseType[self::REQUIRES_PAYMENT] ?? false) === true);
	}//end requiresPayment()

	/**
	 * The sentence a refused act carries, or an empty string when the act may
	 * proceed.
	 *
	 * @param array<string, mixed>      $case     The case, carrying its projection.
	 * @param array<string, mixed>|null $caseType The case type, carrying the rule.
	 *
	 * @return string The refusal, '' when nothing stands in the way.
	 *
	 * @spec openspec/changes/fees-and-payments-on-the-case/specs/financial-integration/spec.md#requirement-a-case-type-decides-whether-an-unpaid-case-proceeds-req-fee-04
	 */
	public function whyItWaits(array $case, ?array $caseType): string {
		if ($this->requiresPayment(caseType: $caseType) === false) {
			return '';
		}

		$state = (string)($case[self::CASE_STATE] ?? CasePaymentState::STALE);

		if ($this->states->isKnown(state: $state) === false) {
			// ADR-102: the config could not be read, so the act fails closed
			// and the sentence says which service went quiet. A handler who
			// reads "the payment could not be checked" phones the right team;
			// one who reads "payment outstanding" argues with a citizen who
			// has the receipt.
			return 'This case type requires the payment to be settled first, and the payment service could not be reached to check it.';
		}

		if ($state === CasePaymentState::OUTSTANDING) {
			return 'This case type requires the payment to be settled first, and it is still outstanding.';
		}

		return '';
	}//end whyItWaits()
}//end class
