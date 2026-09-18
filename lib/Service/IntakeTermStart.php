<?php

/**
 * When a request arrived, and when its clock actually starts.
 *
 * 🔴 SOMEONE FILES ON SUNDAY EVENING AND COUNTS FROM SUNDAY EVENING. The
 * municipality counts from Monday morning, and until now nothing said so. The
 * two are eight weeks apart from different starting points, and the first the
 * citizen hears of it is when a decision they think is late is not.
 *
 * 🔴 BOTH MOMENTS ARE STORED, AND NEITHER IS RECOMPUTED ON READ. A handler
 * answering a complaint in March has to be able to say what the citizen was
 * told in January. A calendar that has since gained a holiday would give a
 * different answer to the same question, and a recomputed answer is not the
 * one that was sent.
 *
 * 🔴 IT STAMPS NOTHING IT CANNOT STAND BEHIND. When the organisation calendar
 * does not answer, `termStartsAt` and the flag are left unset rather than
 * guessed from a weekday rule of dossiq's own. A second calendar here would
 * eventually disagree with the one the term counts on, and the disagreement
 * surfaces as a citizen quoting a date the system does not recognise.
 * `receivedAt` is still stamped, because when a request arrived is not the
 * calendar's to know.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use OCA\Dossiq\Service\Termijn\WorkingDayRoll;

/**
 * The two intake moments and the flag between them.
 *
 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md
 */
class IntakeTermStart {

	/**
	 * The property holding when the submission arrived.
	 *
	 * @var string
	 */
	public const RECEIVED_AT = 'receivedAt';

	/**
	 * The property holding the first working moment the term counts from.
	 *
	 * @var string
	 */
	public const TERM_STARTS_AT = 'termStartsAt';

	/**
	 * The property holding whether the two differ.
	 *
	 * @var string
	 */
	public const OUTSIDE_WORKING_HOURS = 'receivedOutsideWorkingHours';

	/**
	 * Constructor.
	 *
	 * @param WorkingDayRoll $calendar The one calendar the term also counts on.
	 */
	public function __construct(
		private readonly WorkingDayRoll $calendar,
	) {
	}//end __construct()

	/**
	 * The three values to stamp on a case that has just arrived.
	 *
	 * @param DateTimeImmutable $received When the submission arrived.
	 *
	 * @return array<string, mixed> The properties to write, `receivedAt` always.
	 *
	 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md
	 */
	public function stampFor(DateTimeImmutable $received): array {
		$stamp = [self::RECEIVED_AT => $received->format(DateTimeImmutable::ATOM)];

		$start = $this->calendar->firstWorkingMomentAtOrAfter(moment: $received);
		if ($start === null) {
			// Nothing else is written. An absent `termStartsAt` reads on every
			// surface as "we are not saying", which is honest; a guessed one
			// reads as a promise.
			return $stamp;
		}

		$stamp[self::TERM_STARTS_AT] = $start->format(DateTimeImmutable::ATOM);
		// 🔴 COMPARED AS INSTANTS, NOT AS FORMATTED STRINGS. A calendar that
		// answers the same moment in another timezone formats differently and
		// means the same thing, and a string comparison would stamp the flag
		// true for a request filed on Tuesday at ten.
		$stamp[self::OUTSIDE_WORKING_HOURS] = ($start->getTimestamp() !== $received->getTimestamp());

		return $stamp;
	}//end stampFor()

	/**
	 * Whether a case already carries the stamp.
	 *
	 * Asked before writing, because the stamp belongs to the moment the case
	 * was created and a second write would move it. A case imported with its
	 * own `receivedAt` keeps it: the request arrived when it arrived, whatever
	 * day dossiq first saw the record.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return boolean True when it is already stamped.
	 *
	 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md
	 */
	public function isStamped(array $case): bool {
		return (trim((string)($case[self::RECEIVED_AT] ?? '')) !== '');
	}//end isStamped()

	/**
	 * Whether this case was filed outside the working window.
	 *
	 * Reads the STORED flag and never recomputes it, which is the whole reason
	 * the flag is stored: what the citizen was told does not move.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return boolean True when the two moments differ.
	 *
	 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md
	 */
	public function wasOutsideWorkingHours(array $case): bool {
		return filter_var(
			($case[self::OUTSIDE_WORKING_HOURS] ?? false),
			FILTER_VALIDATE_BOOLEAN
		);
	}//end wasOutsideWorkingHours()
}//end class
