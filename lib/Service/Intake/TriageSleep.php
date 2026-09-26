<?php

/**
 * Dossiq triage sleep.
 *
 * "Niets doen tot 1 maart" is a decision a behandelaar makes daily and a state
 * nobody had a name for. Plane calls it `snoozed_till` and puts it on the
 * intake item, before it is a case. This puts it on the intake log entry, for
 * the same reason.
 *
 * A SLEEP IS NOT A HOLD. A hold sits on a case that exists and whose statutory
 * clock runs, and suspending that clock is the opschorting act, with its own
 * grounds and its own notification. A sleep sits on something not yet accepted,
 * where no clock has started. Keeping them apart is what stops a sleep from
 * ever being mistaken for a suspension, so an item that already became a case
 * cannot be slept at all: the refusal names the suspension act instead.
 *
 * AN ITEM THAT WAKES GOES BACK TO THE QUEUE, NOT TO A PERSON. Whoever slept it
 * may have left, changed teams or be on leave on the day it wakes. Waking to
 * the top of an absent person's list is the same as not waking.
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
 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Intake;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\Email\IntakeLog;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Putting a triage item to sleep until a date, and waking it.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.StaticAccess) — `RefusedException::indeterminate()` is
 *  a named constructor, not a service call. It holds no state and exists so a
 *  caller cannot build a refusal with the wrong status on it.
 *
 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
 */
class TriageSleep {

	/**
	 * The entry field holding the date it wakes.
	 */
	public const FIELD_UNTIL = 'sleepUntil';

	/**
	 * The entry field holding why it sleeps.
	 */
	public const FIELD_REASON = 'sleepReason';

	/**
	 * The rule a sleep without a date names.
	 */
	public const RULE_NO_DATE = 'sleep-carries-no-date';

	/**
	 * The rule a sleep without a reason names.
	 */
	public const RULE_NO_REASON = 'sleep-carries-no-reason';

	/**
	 * The rule a sleep in the past names.
	 */
	public const RULE_DATE_PASSED = 'sleep-date-has-passed';

	/**
	 * The rule a sleep on an accepted case names.
	 */
	public const RULE_ALREADY_A_CASE = 'sleep-is-not-a-suspension';

	/**
	 * The rule a sleep on an item nothing can find names.
	 */
	public const RULE_NO_ITEM = 'triage-item-not-found';

	/**
	 * The outcomes an item still waiting for a person can carry.
	 *
	 * @var array<int, string>
	 */
	public const SLEEPABLE_OUTCOMES = [IntakeLog::OUTCOME_QUARANTINED, IntakeLog::OUTCOME_INBOX];

	/**
	 * Constructor.
	 *
	 * @param IntakeLog          $log   The intake log the triage queue reads.
	 * @param CaseDateNormaliser $dates The one class that reads a date.
	 * @param ITimeFactory       $time  Clock.
	 */
	public function __construct(
		private readonly IntakeLog $log,
		private readonly CaseDateNormaliser $dates,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * Put one triage item to sleep until a date.
	 *
	 * @param string $entryId The intake log entry.
	 * @param string $until   The date it comes back, as YYYY-MM-DD.
	 * @param string $reason  Why nothing is done until then.
	 * @param string $actorId The uid of the person sleeping it.
	 *
	 * @return array{sleepUntil: string, sleepReason: string, sleptBy: string, sleptAt: string}
	 *
	 * @throws RefusedException When the date, the reason or the item refuses it.
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
	 */
	public function sleep(string $entryId, string $until, string $reason, string $actorId): array {
		$why = trim($reason);
		if ($why === '') {
			throw new RefusedException(
				rule: self::RULE_NO_REASON,
				sentence: 'A sleeping item carries the reason it sleeps, so the next person '
					. 'to see it knows what was decided.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$wakeDate = $this->dates->toCalendarDateOrNull(value: $until);
		if ($wakeDate === null) {
			throw new RefusedException(
				rule: self::RULE_NO_DATE,
				sentence: 'A sleeping item carries the date it comes back, as a calendar date.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		if ($wakeDate <= $this->today()) {
			throw new RefusedException(
				rule: self::RULE_DATE_PASSED,
				sentence: 'That date has already passed, so the item would wake the moment '
					. 'it went to sleep.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$entry = $this->log->find(entryId: $entryId);
		if ($entry === null) {
			throw new RefusedException(
				rule: self::RULE_NO_ITEM,
				sentence: 'There is no triage item with that number, so nothing was slept.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$this->assertNotYetACase(entry: $entry);

		$record = [
			self::FIELD_UNTIL => $wakeDate,
			self::FIELD_REASON => $why,
			'sleptBy' => $actorId,
			'sleptAt' => $this->time->getDateTime()->format(DATE_ATOM),
		];

		if ($this->log->amend(entryId: $entryId, changes: $record) === false) {
			throw RefusedException::indeterminate(
				rule: self::RULE_NO_ITEM,
				sentence: 'The sleep could not be recorded on the item, so nothing was slept.',
			);
		}

		return $record;
	}//end sleep()

	/**
	 * Whether one entry is asleep right now.
	 *
	 * @param array<string, mixed> $entry The intake log entry.
	 *
	 * @return boolean True when its wake date is still ahead.
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
	 */
	public function isAsleep(array $entry): bool {
		$until = $this->dates->toCalendarDateOrNull(value: (string)($entry[self::FIELD_UNTIL] ?? ''));
		if ($until === null) {
			return false;
		}

		return ($until > $this->today());
	}//end isAsleep()

	/**
	 * The queue with its sleeping items taken out.
	 *
	 * @param array<int, array<string, mixed>> $queue The triage queue.
	 *
	 * @return array<int, array<string, mixed>> The items a handler should see.
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
	 */
	public function awake(array $queue): array {
		$awake = [];
		foreach ($queue as $entry) {
			if ($this->isAsleep(entry: $entry) === false) {
				$awake[] = $entry;
			}
		}

		return $awake;
	}//end awake()

	/**
	 * Wake every item whose date has come.
	 *
	 * Waking clears the sleep and nothing else: the item returns to the queue
	 * it left, unassigned, because the person who slept it may be gone. The
	 * reason is kept, so the queue can still say why it was asleep.
	 *
	 * @return array<int, string> The ids of the items that woke.
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
	 */
	public function wakeDue(): array {
		$woken = [];
		foreach ($this->sleeping() as $entry) {
			if ($this->isAsleep(entry: $entry) === true) {
				continue;
			}

			$entryId = (string)($entry['@self']['id'] ?? ($entry['id'] ?? ''));
			if ($entryId === '') {
				continue;
			}

			$amended = $this->log->amend(
				entryId: $entryId,
				changes: [self::FIELD_UNTIL => '', 'sleptBy' => ''],
			);
			if ($amended === true) {
				$woken[] = $entryId;
			}
		}

		return $woken;
	}//end wakeDue()

	/**
	 * Refuse a sleep on an item that is already an accepted case.
	 *
	 * @param array<string, mixed> $entry The intake log entry.
	 *
	 * @return void
	 *
	 * @throws RefusedException When the item became a case.
	 */
	private function assertNotYetACase(array $entry): void {
		$outcome = trim((string)($entry['outcome'] ?? ''));
		$caseId = trim((string)($entry['case'] ?? ''));
		if ($caseId === '' && in_array($outcome, self::SLEEPABLE_OUTCOMES, true) === true) {
			return;
		}

		throw new RefusedException(
			rule: self::RULE_ALREADY_A_CASE,
			sentence: 'This is an accepted case with a term of its own, and sleeping never stops '
				. 'a statutory clock. Suspend the term on the case instead.',
			status: RefusedException::STATUS_UNPROCESSABLE,
		);
	}//end assertNotYetACase()

	/**
	 * Every entry carrying a wake date.
	 *
	 * @return array<int, array<string, mixed>> The sleeping entries.
	 */
	private function sleeping(): array {
		$entries = [];
		foreach (self::SLEEPABLE_OUTCOMES as $outcome) {
			foreach ($this->log->search(filters: ['outcome' => $outcome]) as $entry) {
				if (trim((string)($entry[self::FIELD_UNTIL] ?? '')) !== '') {
					$entries[] = $entry;
				}
			}
		}

		return $entries;
	}//end sleeping()

	/**
	 * Today, as a calendar date.
	 *
	 * 🔴 A SLEEP IS A CALENDAR DECISION, AND EVERY COMPARISON HERE IS BETWEEN
	 * TWO `Y-m-d` STRINGS. Comparing moments would wake an item at midnight on
	 * one instance and at noon on another, and building a `DateTimeImmutable`
	 * here would be a second rule for what a date is, which
	 * {@see \OCA\Dossiq\Service\CaseDateNormaliser} exists to be the only one
	 * of. `Y-m-d` sorts lexicographically, so `<=` and `>` are the calendar
	 * comparison without a date object in sight.
	 *
	 * @return string Today as `Y-m-d`, on the clock this service was given.
	 */
	private function today(): string {
		return $this->time->getDateTime()->format('Y-m-d');
	}//end today()

	/**
	 * The wake date one entry carries, for a surface that shows it.
	 *
	 * @param array<string, mixed> $entry The intake log entry.
	 *
	 * @return string The date as YYYY-MM-DD, or '' when it is not asleep.
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
	 */
	public function wakeDateOf(array $entry): string {
		return (string)$this->dates->toCalendarDateOrNull(
			value: (string)($entry[self::FIELD_UNTIL] ?? '')
		);
	}//end wakeDateOf()

	/**
	 * Whether a date string reads as a calendar date this service accepts.
	 *
	 * @param string $value The candidate date.
	 *
	 * @return boolean True when it parses.
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
	 */
	public function readsAsDate(string $value): bool {
		return ($this->dates->toCalendarDateOrNull(value: $value) !== null);
	}//end readsAsDate()
}//end class
