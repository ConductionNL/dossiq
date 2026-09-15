<?php

/**
 * Dossiq TermCalendarGuard.
 *
 * A term that NAMES a working calendar refuses when that calendar does not
 * resolve.
 *
 * A term naming no calendar falls back to the local one and says so in the log,
 * which is what `every-term-on-the-engine-calendar` shipped and what an install
 * without OpenRegister needs. A term that NAMES one is a different case:
 * somebody administered a calendar, wrote its name on the term, and an answer
 * computed on a different set of holidays is a statutory date that is wrong with
 * nothing on screen to say so.
 *
 * It lives here rather than inside {@see TermijnTimerService} because the
 * refusal is its own concern and because folding it into `rollOnCalendar()` put
 * that class over its complexity ceiling. The timer service asks this once and
 * keeps the shape it shipped with.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Exception\RefusedException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Refusing a term whose named calendar does not resolve (REQ-TERM-060).
 *
 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
 */
class TermCalendarGuard {
	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Refuse when the term names a calendar the engine cannot answer for.
	 *
	 * A term naming nothing returns quietly: the caller's documented fallback
	 * is the right answer for it, and turning an absent engine into a refusal
	 * would break every install that does not run OpenRegister.
	 *
	 * @param string|null $calendarSlug The calendar named on the term, when any.
	 * @param string|null $organisation The subject's organisation, when any.
	 * @param object|null $calendars The engine's calendar resolver, when it is available.
	 *
	 * @return void
	 *
	 * @throws RefusedException When a NAMED calendar does not resolve.
	 *
	 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
	 */
	public function requireNamedCalendarResolves(
		?string $calendarSlug,
		?string $organisation,
		?object $calendars,
	): void {
		$slug = trim((string)$calendarSlug);
		if ($slug === '') {
			return;
		}

		if ($calendars === null) {
			throw $this->refuse(slug: $slug, because: 'OpenRegister is not installed');
		}

		try {
			$calendar = $calendars->resolve(calendarSlug: $slug, organisation: $organisation);
		} catch (Throwable $e) {
			// Re-thrown, never swallowed: an unreadable calendar and a calendar
			// that says "this is a working day" are opposite answers.
			throw $this->refuse(slug: $slug, because: 'the engine calendar could not be read', previous: $e);
		}

		if ($calendar === null) {
			throw $this->refuse(slug: $slug, because: 'the engine knows no calendar by that name');
		}
	}//end requireNamedCalendarResolves()

	/**
	 * The refusal, logged and built.
	 *
	 * @param string $slug The calendar the term names.
	 * @param string $because What was absent, so the operator can tell an
	 *        uninstalled engine from an unknown name.
	 * @param Throwable|null $previous The failure underneath, when there was one.
	 *
	 * @return RefusedException The refusal to throw.
	 *
	 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
	 */
	private function refuse(string $slug, string $because, ?Throwable $previous = null): RefusedException {
		$this->logger->warning(
			'Dossiq termijn: a term names a working calendar that does not resolve, so binding is refused',
			['calendar' => $slug, 'because' => $because]
		);

		return new RefusedException(
			rule: 'term-calendar-unresolved',
			sentence: 'The working calendar "' . $slug . '" could not be read, so this term was not bound.',
			status: RefusedException::STATUS_INDETERMINATE,
			previous: $previous,
		);
	}//end refuse()
}//end class
