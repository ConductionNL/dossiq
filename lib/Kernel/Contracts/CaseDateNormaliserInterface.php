<?php

/**
 * Contract of CaseDateNormaliser
 *
 * The public surface of CaseDateNormaliser that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Contract of CaseDateNormaliser.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseDateNormaliserInterface {

	/**
	 * An already parsed moment as a stored moment.
	 *
	 * @param DateTimeInterface $moment The moment to store.
	 *
	 * @return string The moment in ATOM form, in the administered zone.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function formatMoment(DateTimeInterface $moment): string;

	/**
	 * Now, in the administered zone.
	 *
	 * The instant comes from the injected clock, not from `new
	 * DateTimeImmutable('now')`. Reading the clock inline left every test that
	 * asked what day it is coupled to the day it ran, and three of them turned
	 * red on 2026-09-19 at 22:00 UTC, which is midnight in Amsterdam: the test
	 * built its fixture in the process zone while this class answered in the
	 * administered one, so for two hours a night the two disagreed by a day.
	 *
	 * @return DateTimeImmutable The current moment.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function now(): DateTimeImmutable;

	/**
	 * Parse a submitted value, refusing anything unreadable.
	 *
	 * A value carrying its own offset keeps that instant and is re-read in
	 * the administered zone. A value without one is read in the administered
	 * zone. A bare date is midnight in the administered zone. So the same day
	 * submitted all three ways is one instant.
	 *
	 * @param mixed $value The submitted value.
	 * @param string $field The field name to name in the refusal.
	 *
	 * @return DateTimeImmutable The parsed moment, in the administered zone.
	 *
	 * @throws InvalidArgumentException When the value is empty or not an ISO 8601 date.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function parse(mixed $value, string $field): DateTimeImmutable;

	/**
	 * The zone every case date is read and written in.
	 *
	 * @return DateTimeZone The engine calendar zone, the tenant zone, or the fallback.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function timeZone(): DateTimeZone;

	/**
	 * A submitted value as the calendar date it names, `Y-m-d`.
	 *
	 * @param mixed $value The submitted value.
	 * @param string $field The field name to name in the refusal.
	 *
	 * @return string The date as `Y-m-d`.
	 *
	 * @throws InvalidArgumentException When the value is unreadable.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function toCalendarDate(mixed $value, string $field): string;

	/**
	 * A stored or reported value as `Y-m-d`, or null when it cannot be read.
	 *
	 * @param mixed $value The value to read.
	 *
	 * @return string|null The date as `Y-m-d`, or null.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function toCalendarDateOrNull(mixed $value): ?string;

	/**
	 * Today, as a stored calendar date.
	 *
	 * @return string Today as `Y-m-d` in the administered zone.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function todayAsCalendarDate(): string;

	/**
	 * Parse a stored or reported value, answering null when it cannot be read.
	 *
	 * For read, report and analytics paths only. A write path calls
	 * {@see parse()}: a null return is what let every caller invent its own
	 * fallback, and two of them stored a typo or substituted today.
	 *
	 * @param mixed $value The value to read.
	 *
	 * @return DateTimeImmutable|null The parsed moment, or null.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function tryParse(mixed $value): ?DateTimeImmutable;
}//end interface
