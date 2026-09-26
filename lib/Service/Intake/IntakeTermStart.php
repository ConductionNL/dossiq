<?php

/**
 * When the request arrived, and when its clock starts.
 *
 * Someone files a request on Sunday evening. The statutory clock does not
 * start on Sunday evening, and until now nothing said so. They counted eight
 * weeks from the moment they pressed send and the municipality counted eight
 * weeks from Monday, and the first anybody heard about the difference was a
 * complaint.
 *
 * 🔴 BOTH MOMENTS ARE STORED, AND NEITHER IS RECOMPUTED ON READ. A handler
 * answering that complaint in March has to be able to say what the citizen was
 * told in January, and a recomputed answer is not that: the calendar may have
 * gained a holiday since, and the recomputation would quietly agree with
 * whatever the calendar says today.
 *
 * 🔴 AN UNREACHABLE CALENDAR STAMPS NOTHING. Writing `receivedAt` alone, or
 * `receivedOutsideWorkingHours: false` on a Sunday, would put a claim on the
 * record that nobody computed. An absent stamp is visibly absent; a false flag
 * is confidently wrong, and the confirmation would then quote a start date the
 * term does not use.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Intake;

use DateTimeImmutable;
use OCA\Dossiq\Service\Termijn\WorkingDayRoll;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The two moments a case is stamped with at intake, and the flag between them.
 *
 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md
 */
class IntakeTermStart {

	/**
	 * The property holding the moment the submission arrived.
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
	public const OUTSIDE = 'receivedOutsideWorkingHours';

	/**
	 * Constructor.
	 *
	 * @param WorkingDayRoll       $calendar The one calendar the term also counts on.
	 * @param LoggerInterface|null $logger   Where an unstamped case is noted.
	 *
	 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md
	 */
	public function __construct(
		private readonly WorkingDayRoll $calendar,
		private readonly ?LoggerInterface $logger = null,
	) {
	}//end __construct()

	/**
	 * The three fields a case is stamped with, or an empty array.
	 *
	 * Empty means the calendar did not answer. The caller writes nothing in
	 * that case, on purpose: see the note on this class.
	 *
	 * @param DateTimeImmutable $receivedAt The moment the submission arrived.
	 *
	 * @return array<string, mixed> The three fields, or [].
	 *
	 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md#requirement-a-case-records-when-it-arrived-and-when-its-clock-starts-req-term-040
	 */
	public function stampFor(DateTimeImmutable $receivedAt): array {
		$startsAt = $this->calendar->firstWorkingMomentAtOrAfter(moment: $receivedAt);
		if ($startsAt === null) {
			$this->logger?->warning(
				'Dossiq intake: the working calendar did not answer, so the case carries no term start',
				['receivedAt' => $receivedAt->format('c')],
			);

			return [];
		}

		return [
			self::RECEIVED_AT => $receivedAt->format('c'),
			self::TERM_STARTS_AT => $startsAt->format('c'),
			// STRICT INEQUALITY OF THE INSTANT, not of the date. The engine
			// answers the moment given when it is inside the working week, so
			// equality is exactly "the clock started when you pressed send".
			self::OUTSIDE => ($startsAt->getTimestamp() !== $receivedAt->getTimestamp()),
		];
	}//end stampFor()

	/**
	 * Whether a case already carries the stamp.
	 *
	 * The stamp is written once and never moved, so this is what stops a
	 * replayed create event or a second listener rewriting it against a
	 * calendar that has changed since.
	 *
	 * @param array<string, mixed> $case The stored case.
	 *
	 * @return bool True when it is already stamped.
	 *
	 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md#requirement-a-case-records-when-it-arrived-and-when-its-clock-starts-req-term-040
	 */
	public function isStamped(array $case): bool {
		return (trim((string)($case[self::TERM_STARTS_AT] ?? '')) !== '');
	}//end isStamped()

	/**
	 * The moment a stored case says it arrived.
	 *
	 * Falls back to the platform's own creation moment, and then to now, so a
	 * case created through a path that names no arrival still gets a stamp
	 * rather than none.
	 *
	 * @param array<string, mixed> $case The stored case.
	 *
	 * @return DateTimeImmutable The arrival moment.
	 *
	 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md#requirement-a-case-records-when-it-arrived-and-when-its-clock-starts-req-term-040
	 */
	public function arrivalOf(array $case): DateTimeImmutable {
		$candidates = [
			(string)($case[self::RECEIVED_AT] ?? ''),
			(string)($case['registrationDate'] ?? ''),
			(string)($case['requestedDate'] ?? ''),
		];

		$self = ($case['@self'] ?? []);
		if (is_array($self) === true) {
			$candidates[] = (string)($self['created'] ?? '');
		}

		foreach ($candidates as $candidate) {
			$candidate = trim($candidate);
			if ($candidate === '') {
				continue;
			}

			try {
				return new DateTimeImmutable($candidate);
			} catch (Throwable $e) {
				continue;
			}
		}

		return new DateTimeImmutable();
	}//end arrivalOf()
}//end class
