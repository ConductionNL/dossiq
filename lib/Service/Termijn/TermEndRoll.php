<?php

/**
 * Dossiq term end roll.
 *
 * The Algemene termijnenwet roll: a term that ends on a Saturday, a Sunday or
 * a public holiday ends on the first ordinary day after it. Art. 1 applies by
 * law and not by configuration, which is why a definition that says nothing
 * gets the roll and the flag exists only to switch it off for a term the Awt
 * does not govern.
 *
 * Split out of {@see \OCA\Dossiq\Service\TermijnTimerService}, which was over
 * its complexity ceiling. That class keeps its four public roll methods as
 * one-line calls into this one, so the twenty-odd sites that ask it to roll a
 * date did not move.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Termijn
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
 * @spec openspec/changes/every-term-on-the-engine-calendar/specs/termijnbewaking-schemas/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Termijn;

use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermCalendarGuard;
use OCA\Dossiq\Service\TermijnTimerService;
use OCA\Dossiq\Service\WorkingDayCalculator;
use Psr\Log\LoggerInterface;

/**
 * Rolls a term end onto the first ordinary day the calendar allows.
 *
 * @spec openspec/changes/every-term-on-the-engine-calendar/specs/termijnbewaking-schemas/spec.md
 */
class TermEndRoll {
	/**
	 * Constructor.
	 *
	 * @param SettingsService           $settingsService  Lazy OpenRegister access.
	 * @param LoggerInterface           $logger           What a fallback writes about itself.
	 * @param WorkingDayCalculator|null $fallbackCalendar The documented fallback for an
	 *        absent engine; built here when the container does not supply one.
	 * @param TermCalendarGuard|null    $calendarGuard    Refuses a term whose NAMED
	 *        calendar does not resolve.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly ?WorkingDayCalculator $fallbackCalendar = null,
		private readonly ?TermCalendarGuard $calendarGuard = null,
	) {
	}//end __construct()

	/**
	 * Move a statutory end date off a non-working day, on the calendar the
	 * organisation administers.
	 *
	 * Algemene termijnenwet art. 1: a term ending on a Saturday, a Sunday or a
	 * generally recognised holiday runs to the next ordinary day. The roll is
	 * CONSUMED, not reimplemented: the engine's `SlaCalculator` walks its own
	 * `businessDays` unit over the resolved `WorkingCalendar`, so dossiq never
	 * decides which days are holidays and never holds a second list.
	 *
	 * When OpenRegister is absent the call degrades to
	 * {@see WorkingDayCalculator}, the one local holiday list this app is
	 * allowed to keep, and says so in the log. That is the only working-day
	 * arithmetic left in `lib/`, and it is behind the engine's absence.
	 *
	 * 🔑 THIS CALL ALWAYS ROLLS. It took a `bool $roll` until 2026-09-20, and a
	 * boolean that picks between "do the work" and "hand the argument back" is
	 * two methods wearing one name. The two are now named: this one rolls, and
	 * {@see rollTermEndFor()} reads the declared flag and decides whether to
	 * call it. Nothing is lost, because the flag only ever arrived from
	 * `rollEnabled()` one line up.
	 *
	 * @param DateTimeImmutable $date The computed end date.
	 * @param string|null $calendarSlug The calendar named on the term, when any.
	 * @param string|null $organisation The subject's organisation, when any.
	 *
	 * @return DateTimeImmutable The first ordinary day on or after the date.
	 *
	 * @throws RefusedException When the term NAMES a calendar the engine cannot resolve.
	 *
	 * @spec openspec/changes/every-term-on-the-engine-calendar/specs/termijnbewaking-schemas/spec.md
	 */
	public function rollTermEnd(
		DateTimeImmutable $date,
		?string $calendarSlug = null,
		?string $organisation = null,
	): DateTimeImmutable {
		return $this->rollOnCalendar(date: $date, calendarSlug: $calendarSlug, organisation: $organisation);
	}//end rollTermEnd()

	/**
	 * The call every term site makes: roll this end date if the term declares
	 * the roll, on the calendar the organisation administers.
	 *
	 * One expression per site, so a site cannot half-adopt the calendar. The
	 * primitive is {@see rollTermEnd()}; this reads the declared flag first.
	 *
	 * @param DateTimeImmutable $date The computed end date.
	 * @param array<string, mixed> $definitie The term definition, when one is known.
	 * @param string|null $calendarSlug The calendar named on the term, when any.
	 * @param string|null $organisation The subject's organisation, when any.
	 *
	 * @return DateTimeImmutable The day the term actually ends on.
	 *
	 * @throws RefusedException When the term NAMES a calendar the engine cannot resolve.
	 *
	 * @spec openspec/changes/every-term-on-the-engine-calendar/specs/termijnbewaking-schemas/spec.md
	 */
	public function rollTermEndFor(
		DateTimeImmutable $date,
		array $definitie = [],
		?string $calendarSlug = null,
		?string $organisation = null,
	): DateTimeImmutable {
		if ($this->rollEnabled(definitie: $definitie) === false) {
			return $date;
		}

		return $this->rollTermEnd(
			date: $date,
			calendarSlug: $calendarSlug,
			organisation: $organisation
		);
	}//end rollTermEndFor()

	/**
	 * Whether a term declares the Algemene termijnenwet roll.
	 *
	 * `deadlineDefinition.rollToWorkingDay` decides, and
	 * `terms-on-the-engine-calendar` owns that property. A definition that does
	 * not carry it gets the roll, because Awt art. 1 applies by law and not by
	 * configuration; the flag exists to switch it OFF for a term the Awt does
	 * not govern.
	 *
	 * @param array<string, mixed> $definitie The resolved TermijnDefinitie (may be empty).
	 *
	 * @return bool True when the end date rolls.
	 *
	 * @spec openspec/changes/every-term-on-the-engine-calendar/specs/termijnbewaking-schemas/spec.md
	 */
	public function rollEnabled(array $definitie): bool {
		if (array_key_exists('rollToWorkingDay', $definitie) === false) {
			return true;
		}

		return (bool)$definitie['rollToWorkingDay'];
	}//end rollEnabled()

	/**
	 * Whether an organisation calendar is answering at all.
	 *
	 * ASKED SO A SURFACE CAN SAY WHICH IT IS. A roll that was not needed and a
	 * roll that could not be made produce the same plausible date, so a page
	 * that shows the date and nothing else cannot tell an administrator that
	 * the Awt rule is currently inert on this instance.
	 *
	 * Deliberately not inferred from a roll's result: the roll falls back
	 * silently by design, because a term must still get a date.
	 *
	 * @return boolean True when both engine classes resolve.
	 *
	 * @spec openspec/changes/terms-on-the-engine-calendar/specs/termijnbewaking-schemas/spec.md
	 */
	public function calendarAnswers(): bool {
		return ($this->settingsService->getOpenRegisterClass(TermijnTimerService::CALENDAR_SERVICE_CLASS) !== null
			&& $this->settingsService->getOpenRegisterClass(TermijnTimerService::SLA_CALCULATOR_CLASS) !== null);
	}//end calendarAnswers()

	/**
	 * The roll as the engine computes it, falling back when it cannot answer.
	 *
	 * @param DateTimeImmutable $date The computed end date.
	 * @param string|null $calendarSlug The calendar named on the term, when any.
	 * @param string|null $organisation The subject's organisation, when any.
	 *
	 * @return DateTimeImmutable The first ordinary day on or after the date.
	 */
	private function rollOnCalendar(
		DateTimeImmutable $date,
		?string $calendarSlug,
		?string $organisation,
	): DateTimeImmutable {
		$calendars = $this->settingsService->getOpenRegisterClass(TermijnTimerService::CALENDAR_SERVICE_CLASS);
		$calculator = $this->settingsService->getOpenRegisterClass(TermijnTimerService::SLA_CALCULATOR_CLASS);

		// A term that NAMES a calendar refuses when that calendar does not
		// resolve, rather than answering on a different one (REQ-TERM-060). The
		// decision belongs to the guard, so the rest of this method keeps the
		// shape `every-term-on-the-engine-calendar` shipped: a term naming no
		// calendar still falls back, and still says so in the log.
		$this->calendarGuard?->requireNamedCalendarResolves(
			calendarSlug: $calendarSlug,
			organisation: $organisation,
			calendars: $calendars
		);

		if ($calendars === null || $calculator === null) {
			return $this->fallbackRoll(date: $date, because: 'OpenRegister is not installed');
		}

		try {
			$calendar = $calendars->resolve(calendarSlug: $calendarSlug, organisation: $organisation);

			return $calculator->add(
				from: $date,
				value: 0.0,
				unit: TermijnTimerService::UNIT_BUSINESS_DAYS,
				calendar: $calendar
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Dossiq termijn: engine timer call failed, domain flow continues on case data',
				['operation' => 'roll to working day', 'id' => $date->format('Y-m-d'), 'error' => $e->getMessage()]
			);
			return $this->fallbackRoll(date: $date, because: 'the engine calendar could not be read');
		}
	}//end rollOnCalendar()

	/**
	 * The roll on dossiq's own calendar, used only when the engine cannot answer.
	 *
	 * @param DateTimeImmutable $date The computed end date.
	 * @param string $because What was absent, so the operator can tell an
	 *        uninstalled engine from a broken calendar.
	 *
	 * @return DateTimeImmutable The first ordinary day on or after the date.
	 */
	private function fallbackRoll(DateTimeImmutable $date, string $because): DateTimeImmutable {
		$calculator = ($this->fallbackCalendar ?? new WorkingDayCalculator());
		$rolled = $calculator->nextWorkingDay(date: $date);

		$this->logger->info(
			'Dossiq termijn: engine calendar unavailable, term end rolled on the local calendar',
			['date' => $date->format('Y-m-d'), 'rolled' => $rolled->format('Y-m-d'), 'because' => $because]
		);

		return $rolled;
	}//end fallbackRoll()
}//end class
