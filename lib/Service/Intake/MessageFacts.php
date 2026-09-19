<?php

/**
 * Dossiq intake message facts.
 *
 * What a routed message says about itself, before anything decides what to do
 * with it: the title a case opens under, the handle that wrote in, and the day
 * it arrived.
 *
 * Split out of {@see ChannelIntake}, which was over its complexity ceiling.
 * Reading a message is not the same job as opening a case from one, and this
 * half has no register and no writes.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Intake
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
 * @spec openspec/changes/an-intake-message-opens-a-case/specs/intake-from-a-channel/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Intake;

use OCA\Dossiq\Service\CaseDateNormaliser;

/**
 * What a routed message says about itself.
 *
 * @spec openspec/changes/an-intake-message-opens-a-case/specs/intake-from-a-channel/spec.md
 */
class MessageFacts {
	/**
	 * Constructor.
	 *
	 * @param CaseDateNormaliser $dates The one date read path.
	 */
	public function __construct(
		private readonly CaseDateNormaliser $dates,
	) {
	}//end __construct()

	/**
	 * A one-line name for the message.
	 *
	 * @param array<string, mixed> $message The message.
	 * @param array<string, mixed> $payload The rule's mapped payload.
	 *
	 * @return string The title.
	 */
	public function titleFor(array $message, array $payload): string {
		$mapped = ($payload['title'] ?? null);
		if (is_scalar($mapped) === true && trim((string)$mapped) !== '') {
			return mb_substr(trim((string)$mapped), 0, 255);
		}

		$text = trim((string)($message['text'] ?? ''));
		if ($text === '') {
			return ChannelIntake::UNTITLED;
		}

		$firstLine = trim((string)(preg_split('/\R/', $text)[0] ?? ''));

		if ($firstLine === '') {
			return ChannelIntake::UNTITLED;
		}

		return mb_substr($firstLine, 0, 120);
	}//end titleFor()

	/**
	 * Who wrote, as the channel gave them.
	 *
	 * 🔴 NO PERSON RECORD IS CREATED HERE. A telephone number that wrote in
	 * once is not a citizen record, and a register filling up with them is
	 * worse than a case naming a string.
	 *
	 * @param array<string, mixed> $message The message.
	 *
	 * @return string The correspondent, or ''.
	 */
	public function correspondent(array $message): string {
		$correspondent = ($message['correspondent'] ?? null);
		if (is_scalar($correspondent) === true) {
			return trim((string)$correspondent);
		}

		if (is_array($correspondent) === false) {
			return '';
		}

		foreach (['address', 'handle', 'id', 'name'] as $key) {
			$value = ($correspondent[$key] ?? null);
			if (is_scalar($value) === true && trim((string)$value) !== '') {
				return trim((string)$value);
			}
		}

		return '';
	}//end correspondent()

	/**
	 * The day the message came in, as `Y-m-d`.
	 *
	 * Read through {@see CaseDateNormaliser}, which is the one path a date is
	 * written by. A private parser here would be a second rule for what a date
	 * is, and the statutory clock starts on this value: an instant read in the
	 * process time zone rather than the administered one moves a deadline by a
	 * day between two servers.
	 *
	 * An unreadable stamp falls back to today rather than refusing. The
	 * message did arrive; the day it says so is the channel's to get right.
	 *
	 * @param array<string, mixed> $message The message.
	 *
	 * @return string The date.
	 */
	public function receivedDate(array $message): string {
		$received = $this->dates->toCalendarDateOrNull(value: ($message['receivedAt'] ?? null));

		return ($received ?? $this->dates->today()->format('Y-m-d'));
	}//end receivedDate()
}//end class
