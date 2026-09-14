<?php

/**
 * Dossiq Out of Office Filter
 *
 * An afwezigheidsbericht is the one auto-reply that often forgets to say it is
 * one, because a person wrote the text. So this filter reads the headers the
 * out-of-office responders do set, and the subject as a last resort, in both
 * languages the app ships.
 *
 * It is deliberately the filter AFTER the general auto-reply one: a message
 * that already declared itself automatic should be recorded under that name,
 * because a handler reading the log wants the strongest evidence named, not the
 * most specific guess.
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
 * Refuses an out-of-office answer.
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
class OutOfOfficeFilter implements InboundMailFilter {

	/**
	 * The name the intake log records.
	 */
	public const NAME = 'out-of-office';

	/**
	 * Subject openings an out-of-office responder writes.
	 *
	 * Matched at the START of the subject only. A bezwaar whose text mentions
	 * an afwezigheidsbericht is still a bezwaar, and a substring match
	 * anywhere in the line would have refused it.
	 *
	 * @var string[]
	 */
	private const SUBJECT_OPENINGS = [
		'out of office',
		'out-of-office',
		'automatic reply',
		'automatische beantwoording',
		'automatisch antwoord',
		'afwezigheidsbericht',
		'afwezig:',
		'niet aanwezig:',
	];

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
		return 40;
	}//end order()

	/**
	 * Whether this is somebody's absence notice.
	 *
	 * @param InboundMessage $message The message.
	 *
	 * @return FilterVerdict Reject when it is, pass otherwise.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function decide(InboundMessage $message): FilterVerdict {
		if ($message->hasHeader(name: 'x-ms-exchange-inbox-rules-loop') === true) {
			return FilterVerdict::reject(
				filterName: self::NAME,
				reason: 'The message carries the Exchange out-of-office loop header.'
			);
		}

		$subject = strtolower(trim($message->subject));
		$subject = preg_replace('/^((re|fw|fwd|aw|antw)\s*:\s*)+/i', '', $subject);
		if (is_string($subject) === false) {
			return FilterVerdict::pass(filterName: self::NAME);
		}

		foreach (self::SUBJECT_OPENINGS as $opening) {
			if (str_starts_with($subject, $opening) === true) {
				return FilterVerdict::reject(
					filterName: self::NAME,
					reason: 'The subject opens with "' . $opening . '", which is an absence notice.'
				);
			}
		}

		return FilterVerdict::pass(filterName: self::NAME);
	}//end decide()
}//end class
