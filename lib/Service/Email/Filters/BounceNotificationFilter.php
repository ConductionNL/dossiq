<?php

/**
 * Dossiq Bounce Notification Filter
 *
 * A delivery failure notification is our own outbound mail coming back. It
 * names a case in its subject, because it quotes the message we sent, so the
 * subject-tag matcher used to file it against that case as if a citizen had
 * replied.
 *
 * 🔴 THE WORD BOUNCE MEANS TWO OPPOSITE THINGS HERE AND THEY MUST NOT BE
 * CONFUSED. This filter is about a bounce MESSAGE, the postmaster's report that
 * delivery failed. {@see \OCA\Dossiq\Service\Email\BounceAction} is about the
 * ACT of bouncing, which under Awb 2:3 means sending a misdirected document on
 * to the right body. One is a thing that arrives, the other a thing we do.
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
 * Refuses a delivery failure notification.
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
class BounceNotificationFilter implements InboundMailFilter {

	/**
	 * The name the intake log records.
	 */
	public const NAME = 'bounce-notification';

	/**
	 * Local parts a mail server reports failures from.
	 *
	 * @var string[]
	 */
	private const POSTMASTER_LOCAL_PARTS = ['mailer-daemon', 'postmaster', 'mail-daemon', 'double-bounce'];

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
		return 20;
	}//end order()

	/**
	 * Whether this is a delivery failure report.
	 *
	 * @param InboundMessage $message The message.
	 *
	 * @return FilterVerdict Reject when it is, pass otherwise.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function decide(InboundMessage $message): FilterVerdict {
		$contentType = strtolower($message->header(name: 'content-type'));
		if (str_contains($contentType, 'report-type=delivery-status') === true) {
			return FilterVerdict::reject(
				filterName: self::NAME,
				reason: 'The message is a multipart/report carrying a delivery status.'
			);
		}

		// An empty Return-Path is the RFC 5321 null reverse-path, which exists
		// so that a bounce cannot itself bounce. Nothing else sends one.
		$returnPath = trim($message->header(name: 'return-path'));
		if ($returnPath === '<>' || $returnPath === '') {
			if ($message->hasHeader(name: 'return-path') === true) {
				return FilterVerdict::reject(
					filterName: self::NAME,
					reason: 'The message carries the null return-path a bounce is sent with.'
				);
			}
		}

		if ($message->hasHeader(name: 'x-failed-recipients') === true) {
			return FilterVerdict::reject(
				filterName: self::NAME,
				reason: 'The message names the recipients delivery failed for.'
			);
		}

		$sender = $message->senderAddress();
		$localPart = strtok($sender, '@');
		if ($localPart !== false && in_array($localPart, self::POSTMASTER_LOCAL_PARTS, true) === true) {
			return FilterVerdict::reject(
				filterName: self::NAME,
				reason: 'The sender is ' . $localPart . ', which is a mail server reporting rather than a person writing.'
			);
		}

		return FilterVerdict::pass(filterName: self::NAME);
	}//end decide()
}//end class
