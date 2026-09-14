<?php

/**
 * Dossiq Blocked Sender Filter
 *
 * An administered block on who may open a case by mail. The allow half of the
 * same control lives in Nextcloud Mail's trusted-sender list and is read
 * elsewhere, because allowing is about authentication and blocking is about
 * who gets a case.
 *
 * Refuses rather than quarantines. A quarantined message from a blocked sender
 * would put the decision back in front of the person who already made it.
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
use OCA\Dossiq\Service\Email\SenderBlocklist;

/**
 * Refuses a sender an administrator blocked.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */
class BlockedSenderFilter implements InboundMailFilter {

	/**
	 * The name the intake log records.
	 */
	public const NAME = 'blocked-sender';

	/**
	 * Constructor.
	 *
	 * @param SenderBlocklist $blocklist The administered block half.
	 */
	public function __construct(
		private readonly SenderBlocklist $blocklist,
	) {
	}//end __construct()

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
		return 50;
	}//end order()

	/**
	 * Whether this sender may open a case.
	 *
	 * @param InboundMessage $message The message.
	 *
	 * @return FilterVerdict Reject when the sender is blocked, pass otherwise.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function decide(InboundMessage $message): FilterVerdict {
		$sender = $message->senderAddress();
		if ($sender === '' || $this->blocklist->blocks(email: $sender) === false) {
			return FilterVerdict::pass(filterName: self::NAME);
		}

		return FilterVerdict::reject(
			filterName: self::NAME,
			reason: 'An administrator blocked ' . $sender . ' from opening cases by mail.'
		);
	}//end decide()
}//end class
