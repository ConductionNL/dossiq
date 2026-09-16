<?php

/**
 * Dossiq ChaseSchedule.
 *
 * When the next reminder is due on a running pause, as pure arithmetic.
 *
 * 🔴 THE COUNT IS THE GUARD, NOT THE CLOCK.
 *
 * Two things can reach a paused term: the engine rung armed when the pause was
 * registered, and the daily sweep that runs when no engine is there to arm one.
 * Either may arrive twice, late, or after somebody already answered. So the
 * decision is never "has the interval passed" on its own. It is "has the
 * interval passed SINCE THE LAST REMINDER THIS PAUSE ACTUALLY SENT, and is
 * there budget left". Both facts live on the instance, both are written in the
 * same save as the send, and a second trigger reading them sees the first one's
 * work. That is what makes the same reminder impossible to send twice.
 *
 * A FIRST REMINDER IS COUNTED FROM THE PAUSE START, later ones from the last
 * send. Counting every one from the start would bunch them up after a reminder
 * that went out late, which is exactly when a citizen least wants three letters
 * in a week.
 *
 * NOTHING HERE READS THE CLOCK OR THE STORE. It takes a moment and answers a
 * question, so the fixture pairs below are the whole test and no test of
 * chasing has to wait a day to run.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Pause
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
 * @spec openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Pause;

use DateTimeImmutable;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\WorkingDayCalculator;

/**
 * The reminder schedule a pause reason declares (REQ-TERM-011).
 *
 * @spec openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md
 *
 * @SuppressWarnings(PHPMD.StaticAccess) {@see PauseReason} is a vocabulary of
 * constants and pure functions over an array, with nothing to inject.
 */
class ChaseSchedule {
	/**
	 * Constructor.
	 *
	 * @param WorkingDayCalculator $calendar The working-day walk. The engine's calendar
	 *        answers a term END date through TermijnTimerService; a reminder is not a
	 *        term end and no citizen is held to it, so the app calendar is enough and
	 *        the schedule stays pure.
	 * @param CaseDateNormaliser $dates The one rule for what a stored date means. A
	 *        private parser here would be a second rule, and the moments read below
	 *        are the ones a reminder is counted from.
	 */
	public function __construct(
		private readonly WorkingDayCalculator $calendar,
		private readonly CaseDateNormaliser $dates,
	) {
	}//end __construct()

	/**
	 * When the next reminder on this pause falls due.
	 *
	 * @param array<string, mixed> $instance The TermijnInstance row.
	 * @param array<string, mixed> $reason   The normalised reason in force.
	 *
	 * @return DateTimeImmutable|null The day, or null when no further reminder is due.
	 *
	 * @spec openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md
	 */
	public function nextChaseOn(array $instance, array $reason): ?DateTimeImmutable {
		if (PauseReason::chases(reason: $reason) === false) {
			return null;
		}

		if ($this->sent(instance: $instance) >= (int)$reason['chaseBudget']) {
			return null;
		}

		$from = $this->countFrom(instance: $instance);
		if ($from === null) {
			return null;
		}

		$interval = (int)$reason['chaseIntervalDays'];
		if ($reason['countsWorkingDays'] === true) {
			return $this->calendar->addWorkingDays(start: $from, days: $interval);
		}

		return $from->modify('+' . $interval . ' days');
	}//end nextChaseOn()

	/**
	 * Whether a reminder is due on this pause right now.
	 *
	 * @param array<string, mixed>   $instance The TermijnInstance row.
	 * @param array<string, mixed>   $reason   The normalised reason in force.
	 * @param DateTimeImmutable|null $now      The moment to judge against.
	 *
	 * @return bool True when one should go out.
	 *
	 * @spec openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md
	 */
	public function chaseDue(array $instance, array $reason, ?DateTimeImmutable $now = null): bool {
		$due = $this->nextChaseOn(instance: $instance, reason: $reason);
		if ($due === null) {
			return false;
		}

		return ($due->setTime(0, 0) <= ($now ?? new DateTimeImmutable())->setTime(0, 0));
	}//end chaseDue()

	/**
	 * Whether the silence on this pause has to be escalated.
	 *
	 * True once the budget is spent, the interval since the last reminder has
	 * passed again, and nothing has been escalated yet. The interval is waited
	 * out deliberately: escalating in the same breath as the last reminder
	 * would tell a handler nobody answered before the applicant had a day.
	 *
	 * @param array<string, mixed>   $instance The TermijnInstance row.
	 * @param array<string, mixed>   $reason   The normalised reason in force.
	 * @param DateTimeImmutable|null $now      The moment to judge against.
	 *
	 * @return bool True when the handler should hear about it.
	 *
	 * @spec openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md
	 */
	public function escalationDue(array $instance, array $reason, ?DateTimeImmutable $now = null): bool {
		if (PauseReason::chases(reason: $reason) === false) {
			return false;
		}

		if (trim((string)($instance['chaseEscalatedAt'] ?? '')) !== '') {
			return false;
		}

		if ($this->sent(instance: $instance) < (int)$reason['chaseBudget']) {
			return false;
		}

		$last = $this->dates->tryParse(value: ($instance['lastChasedAt'] ?? null));
		if ($last === null) {
			return false;
		}

		$interval = (int)$reason['chaseIntervalDays'];
		$after = $last->modify('+' . $interval . ' days');
		if ($reason['countsWorkingDays'] === true) {
			$after = $this->calendar->addWorkingDays(start: $last, days: $interval);
		}

		return ($after->setTime(0, 0) <= ($now ?? new DateTimeImmutable())->setTime(0, 0));
	}//end escalationDue()

	/**
	 * The engine rung offsets for one pause, in days before the pause ends.
	 *
	 * Reminder k lands `interval * k` days into a pause of `durationDays`, so
	 * it is `durationDays - (interval * k)` days before the end, which is the
	 * `preBreach` offset the engine takes. An offset that is not positive is
	 * dropped: a reminder due on or after the day the hersteltermijn runs out
	 * is not a reminder, it is the expiry the helper already fires.
	 *
	 * @param array<string, mixed> $reason       The normalised reason.
	 * @param int                  $durationDays The pause length in days.
	 *
	 * @return array<int, int> The offsets, largest first, one per reminder in budget.
	 *
	 * @spec openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md
	 */
	public function rungOffsets(array $reason, int $durationDays): array {
		if (PauseReason::chases(reason: $reason) === false || $durationDays <= 0) {
			return [];
		}

		$interval = (int)$reason['chaseIntervalDays'];
		$offsets = [];
		for ($k = 1; $k <= (int)$reason['chaseBudget']; $k++) {
			$offset = ($durationDays - ($interval * $k));
			if ($offset <= 0) {
				break;
			}

			$offsets[] = $offset;
		}

		return $offsets;
	}//end rungOffsets()

	/**
	 * How many reminders this pause has already sent.
	 *
	 * @param array<string, mixed> $instance The TermijnInstance row.
	 *
	 * @return int The count, never negative.
	 *
	 * @spec openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md
	 */
	public function sent(array $instance): int {
		return max(0, (int)($instance['chasesSent'] ?? 0));
	}//end sent()

	/**
	 * The moment the next interval is counted from.
	 *
	 * @param array<string, mixed> $instance The TermijnInstance row.
	 *
	 * @return DateTimeImmutable|null The last reminder, else the pause start, else null.
	 */
	private function countFrom(array $instance): ?DateTimeImmutable {
		$last = $this->dates->tryParse(value: ($instance['lastChasedAt'] ?? null));
		if ($last !== null) {
			return $last;
		}

		return $this->dates->tryParse(value: ($instance['pauzeStartDatum'] ?? null));
	}//end countFrom()
}//end class
