<?php

/**
 * What a case's money looks like from here, and nothing more than that.
 *
 * 🔑 THE MONEY IS SHILLINQ'S AND THE STATE IS A PROJECTION (D-2). shillinq
 * holds the fee schedule, the amount, the provider state, the counter payment
 * and the ledger. dossiq holds one word per case, so a list can be filtered
 * and a handler can see whether the leges were paid without opening the panel.
 *
 * 🔴 NOTHING HERE COMPUTES MONEY. Whether a request is paid is shillinq's
 * derivation (`PaymentSettlementService::report()`, which reads the provider
 * state and the counter settlements together and reports an overpayment rather
 * than letting one record replace the other). Re-deriving it here would be a
 * second answer to a question that already has one, and the day they disagree
 * the citizen is right and both systems are wrong. This class maps the answer
 * shillinq gives onto the one word a case carries.
 *
 * 🔴 UNREADABLE IS A STATE OF ITS OWN, AND IT IS NOT PAID. A projection that
 * could not be refreshed reads `stale`. Reading it as paid would let an unpaid
 * case through the gate because shillinq was briefly unreachable, which is the
 * fail-open shape ADR-102 exists to end; reading it as outstanding would be an
 * accusation the record cannot support.
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
 * The case-level payment state, derived from shillinq's own report.
 *
 * @spec openspec/changes/fees-and-payments-on-the-case/specs/financial-integration/spec.md
 */
class CasePaymentState {
	/**
	 * No money is owed on this case: the type publishes no fee, or nothing was
	 * ever raised.
	 *
	 * @var string
	 */
	public const NOT_REQUIRED = 'notRequired';

	/**
	 * Money is owed and has not arrived in full.
	 *
	 * @var string
	 */
	public const OUTSTANDING = 'outstanding';

	/**
	 * Every request raised on this case is settled.
	 *
	 * @var string
	 */
	public const PAID = 'paid';

	/**
	 * The money was let go rather than received, which is a decision somebody
	 * made and not the same fact as payment.
	 *
	 * @var string
	 */
	public const WAIVED = 'waived';

	/**
	 * shillinq could not be asked, so the last word this case carries may be
	 * out of date and is treated as unknown.
	 *
	 * @var string
	 */
	public const STALE = 'stale';

	/**
	 * Every state a case may carry.
	 *
	 * @var array<int, string>
	 */
	public const ALL = [
		self::NOT_REQUIRED,
		self::OUTSTANDING,
		self::PAID,
		self::WAIVED,
		self::STALE,
	];

	/**
	 * shillinq's reported states that mean the money arrived.
	 *
	 * `overpaid` counts as settled here and stays a problem THERE: the refund
	 * is shillinq's to make, and a case blocked on the gate because somebody
	 * paid twice would be the wrong end of that problem.
	 *
	 * @var array<int, string>
	 */
	private const SETTLED = ['paid', 'overpaid'];

	/**
	 * shillinq's reported states that mean it could not do the sum.
	 *
	 * 🔴 THIS IS NOT "MONEY IS OWED". shillinq reports `indeterminate` when a
	 * request's own amount cannot be read as a number (shillinq#1641), so the
	 * amount owed is unknown, not outstanding. Reading it as outstanding sends
	 * a handler to chase a payment that may already have been made, and tells a
	 * citizen with the receipt in their hand that they still owe us.
	 *
	 * @var array<int, string>
	 */
	private const UNREADABLE = ['indeterminate'];

	/**
	 * The method a settlement carries when the money was let go.
	 *
	 * @var string
	 */
	private const WAIVER_METHOD = 'waived';

	/**
	 * The case-level state, from the payment requests shillinq reports.
	 *
	 * The order of the rules is the order of the worries. Anything unsettled
	 * outranks everything else, because an outstanding request is what the
	 * gate is for. A waiver only wins where it is the whole story: a case with
	 * one waived request and one paid request reads paid, since money did
	 * arrive and calling that a waiver would hide it.
	 *
	 * @param array<int, array<string, mixed>> $requests The leaf's items for this case.
	 *
	 * @return string One of the constants above. `stale` is returned only where
	 *                the answer itself says the record could not be read: a
	 *                request shillinq cannot price, or an item this app cannot
	 *                parse. Everything else is an answer and reads as one.
	 *
	 * @spec openspec/changes/fees-and-payments-on-the-case/specs/financial-integration/spec.md#requirement-the-payment-state-is-on-the-case-and-read-from-shillinq-req-fee-02
	 */
	public function fromRequests(array $requests): string {
		if ($requests === []) {
			return self::NOT_REQUIRED;
		}

		$tally = $this->tally(requests: $requests);

		// Outstanding outranks unreadable. A request nobody has paid is a fact
		// that holds whatever the row beside it says, and it is the one a
		// handler can act on. Unreadable outranks every settled answer: this
		// app cannot call a case paid while part of its record is unreadable.
		if ($tally['anyOutstanding'] === true) {
			return self::OUTSTANDING;
		}

		if ($tally['anyUnreadable'] === true) {
			return self::STALE;
		}

		if ($tally['waivedOnly'] === true) {
			return self::WAIVED;
		}

		return self::PAID;
	}//end fromRequests()

	/**
	 * Read the whole list once, and say what it holds.
	 *
	 * The whole list is read before anything is decided, because returning on
	 * the first interesting row made the answer depend on the ORDER the leaf
	 * happened to send: a case with one unpaid request and one unreadable one
	 * read `outstanding` or `stale` by luck of the sort.
	 *
	 * @param array<int, mixed> $requests The payment requests as the leaf sent them.
	 *
	 * @return array{waivedOnly: bool, anyOutstanding: bool, anyUnreadable: bool} What the list holds.
	 */
	private function tally(array $requests): array {
		$waivedOnly = true;
		$anyOutstanding = false;
		$anyUnreadable = false;

		foreach ($requests as $request) {
			if (is_array($request) === false) {
				// Skipping it was worse than it looks: a case whose ONLY request
				// was unparseable fell through to the waiver branch and read
				// `waived`, which opens the gate and says a person decided to let
				// the money go. Nobody decided anything.
				$anyUnreadable = true;
				continue;
			}

			$reported = (string)($request['reported']['state'] ?? '');
			if (in_array($reported, self::UNREADABLE, true) === true) {
				$anyUnreadable = true;
				continue;
			}

			if (in_array($reported, self::SETTLED, true) === false) {
				$anyOutstanding = true;
				continue;
			}

			if ($this->wasWaived(request: $request) === false) {
				$waivedOnly = false;
			}
		}

		return [
			'waivedOnly' => $waivedOnly,
			'anyOutstanding' => $anyOutstanding,
			'anyUnreadable' => $anyUnreadable,
		];
	}//end tally()

	/**
	 * Whether a settled request was settled by letting the money go.
	 *
	 * @param array<string, mixed> $request One leaf item.
	 *
	 * @return bool True when every settlement on it is a waiver.
	 */
	private function wasWaived(array $request): bool {
		$settlements = ($request['settlements'] ?? null);
		if (is_array($settlements) === false || $settlements === []) {
			return false;
		}

		foreach ($settlements as $settlement) {
			if (is_array($settlement) === false
				|| (string)($settlement['method'] ?? '') !== self::WAIVER_METHOD
			) {
				return false;
			}
		}

		return true;
	}//end wasWaived()

	/**
	 * Whether this state is one a reader may act on, or one that says the
	 * record could not be refreshed.
	 *
	 * @param string $state The state to judge.
	 *
	 * @return bool True when the state is known.
	 *
	 * @spec openspec/changes/fees-and-payments-on-the-case/specs/financial-integration/spec.md#requirement-the-payment-state-is-on-the-case-and-read-from-shillinq-req-fee-02
	 */
	public function isKnown(string $state): bool {
		return ($state !== self::STALE && in_array($state, self::ALL, true) === true);
	}//end isKnown()

	/**
	 * The ZGW `betalingsindicatie` this state means, for the ZRC contract.
	 *
	 * DERIVED, never a second source. `paymentIndication` is on the case
	 * because the ZRC API asks for it and integriq maps it out; letting a
	 * handler set it beside this projection would be two answers to one
	 * question. ZGW knows nothing of a waiver and nothing of an unreadable
	 * projection, so both fall back to the value ZGW does have for "not
	 * settled by the citizen": `nvt` for a waiver, because nothing is owed any
	 * more, and NOTHING AT ALL for stale, because the ZRC field would then
	 * claim a fact this app has just said it could not read.
	 *
	 * @param string $state The case-level state.
	 *
	 * @return string|null The ZGW value, or null when this state must not be mapped.
	 *
	 * @spec openspec/changes/fees-and-payments-on-the-case/specs/financial-integration/spec.md#requirement-the-payment-state-is-on-the-case-and-read-from-shillinq-req-fee-02
	 */
	public function zgwIndication(string $state): ?string {
		return match ($state) {
			self::NOT_REQUIRED, self::WAIVED => 'nvt',
			self::OUTSTANDING => 'not_yet',
			self::PAID => 'geheel',
			default => null,
		};
	}//end zgwIndication()

	/**
	 * The projection to write onto a case, with no money in it.
	 *
	 * The shape is the guarantee: a state and the moment it was read, and no
	 * amount, no ledger line and no payment date. A projection carrying an
	 * amount is a second copy of the money, and REQ-FEE-02 forbids it for the
	 * reason every duplicated fact is forbidden.
	 *
	 * @param string $state The case-level state.
	 * @param string $checkedAt When shillinq was last asked, as an ISO 8601 string.
	 *
	 * @return array{paymentState: string, paymentStateCheckedAt: string} The projection.
	 *
	 * @spec openspec/changes/fees-and-payments-on-the-case/specs/financial-integration/spec.md#requirement-the-payment-state-is-on-the-case-and-read-from-shillinq-req-fee-02
	 */
	public function projection(string $state, string $checkedAt): array {
		$known = self::STALE;
		if (in_array($state, self::ALL, true) === true) {
			$known = $state;
		}

		return [
			'paymentState' => $known,
			'paymentStateCheckedAt' => $checkedAt,
		];
	}//end projection()
}//end class
