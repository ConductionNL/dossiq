<?php

/**
 * Dossiq Auto Reply Filter
 *
 * A message a machine sent in answer to something is not an aanvraag. A
 * gemeente mailbox gets more of these than it gets real messages, and every one
 * of them used to walk the same path as a bezwaar.
 *
 * Reads the headers RFC 3834 and the de-facto conventions define, in the order
 * that costs least: `Auto-Submitted` is the standard one, `Precedence` and the
 * `X-Auto*` family are what mail systems actually send.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Email\Filters
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
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email\Filters;

use OCA\Dossiq\Service\Email\InboundMessage;

/**
 * Refuses a machine-generated answer.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 *
 * @SuppressWarnings(PHPMD.StaticAccess) — the static calls here are named
 *  constructors and value-object factories (`InboundMessage::fromRow()`,
 *  `FilterVerdict::accept()`, `AuthenticationVerdict::unknown()`), which hold no
 *  state and exist so a caller cannot build a half-built value.
 */
class AutoReplyFilter implements InboundMailFilter {

	/**
	 * The name the intake log records.
	 */
	public const NAME = 'auto-reply';

	/**
	 * `Precedence` values that mean "do not answer this".
	 *
	 * @var string[]
	 */
	private const BULK_PRECEDENCE = ['bulk', 'list', 'junk', 'auto_reply'];

	/**
	 * The name the intake log records.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function name(): string {
		return self::NAME;
	}//end name()

	/**
	 * Where this filter sits in the declared order.
	 *
	 * @return integer The order.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function order(): int {
		return 30;
	}//end order()

	/**
	 * Whether a machine sent this in answer to something.
	 *
	 * @param InboundMessage $message The message.
	 *
	 * @return FilterVerdict Reject when it is automatic, pass otherwise.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function decide(InboundMessage $message): FilterVerdict {
		return $this->autoSubmittedVerdict(message: $message)
			?? $this->precedenceVerdict(message: $message)
			?? $this->autoHeaderVerdict(message: $message)
			?? FilterVerdict::pass(filterName: self::NAME);
	}//end decide()

	/**
	 * Reject a message that declares it was submitted automatically.
	 *
	 * @param InboundMessage $message The message.
	 *
	 * @return FilterVerdict|null The rejection, or null when it does not declare so.
	 */
	private function autoSubmittedVerdict(InboundMessage $message): FilterVerdict|null {
		$autoSubmitted = strtolower(trim($message->header(name: 'auto-submitted')));
		if ($autoSubmitted !== '' && str_starts_with($autoSubmitted, 'no') === false) {
			return FilterVerdict::reject(
				filterName: self::NAME,
				reason: 'The message declares Auto-Submitted: ' . $autoSubmitted . ', so a machine sent it.'
			);
		}

		return null;
	}//end autoSubmittedVerdict()

	/**
	 * Reject a message that declares a bulk precedence.
	 *
	 * @param InboundMessage $message The message.
	 *
	 * @return FilterVerdict|null The rejection, or null when it does not declare so.
	 */
	private function precedenceVerdict(InboundMessage $message): FilterVerdict|null {
		$precedence = strtolower(trim($message->header(name: 'precedence')));
		if (in_array($precedence, self::BULK_PRECEDENCE, true) === true) {
			return FilterVerdict::reject(
				filterName: self::NAME,
				reason: 'The message declares Precedence: ' . $precedence . ', so it is bulk rather than addressed to us.'
			);
		}

		return null;
	}//end precedenceVerdict()

	/**
	 * Reject a message that carries a header only automatic mail sets.
	 *
	 * @param InboundMessage $message The message.
	 *
	 * @return FilterVerdict|null The rejection, or null when it carries none.
	 */
	private function autoHeaderVerdict(InboundMessage $message): FilterVerdict|null {
		foreach (['x-autoreply', 'x-autorespond', 'x-auto-response-suppress'] as $header) {
			if ($message->hasHeader(name: $header) === true) {
				return FilterVerdict::reject(
					filterName: self::NAME,
					reason: 'The message carries ' . $header . ', which only automatic mail sets.'
				);
			}
		}

		return null;
	}//end autoHeaderVerdict()
}//end class
