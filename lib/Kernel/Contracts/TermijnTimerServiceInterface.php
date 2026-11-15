<?php

/**
 * Contract of TermijnTimerService
 *
 * The public surface of TermijnTimerService that other components call, so they depend
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

/**
 * Contract of TermijnTimerService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TermijnTimerServiceInterface {

	/**
	 * The engine service class, resolved lazily.
	 *
	 * @var string
	 */
	public const ENGINE_CLASS = 'OCA\OpenRegister\Service\Flow\Timer\FlowTimerService';

	/**
	 * Metadata marker the listener filters on.
	 *
	 * @var string
	 */
	public const METADATA_SOURCE = 'dossiq-termijn';

	public const KIND_HERSTELTERMIJN = 'hersteltermijn';

	/**
	 * Arm the beslistermijn timer for a TermijnInstance.
	 *
	 * Maps the instance onto a `due`/`wettelijk` FlowTimer: SLA in
	 * `calendarDays` spanning startDate to endDateCurrent (so an in-flight
	 * instance arms at its CURRENT deadline, pauses and extensions
	 * included), ladder `nl-termijn-default`, anchored at `case_created`
	 * on the start date, extension ceiling from the TermijnDefinitie. No
	 * onExpiry: reaching the beslistermijn never transitions the case.
	 *
	 * @param array<string, mixed> $instance The TermijnInstance row.
	 * @param array<string, mixed> $definitie The resolved TermijnDefinitie (may be empty).
	 *
	 * @return string|null The armed timer uuid, or null when the engine is unavailable.
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md#requirement-a-term-is-late-only-from-the-day-after-its-last-day-everywhere-req-ote-05
	 */
	public function armBeslistermijn(array $instance, array $definitie): ?string;

	/**
	 * Cancel every open timer of an instance, in the operation that made
	 * the term terminal.
	 *
	 * @param string $instanceId The TermijnInstance id.
	 * @param string $reason Why, recorded on each timer.
	 *
	 * @return int How many timers were cancelled.
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function cancelForInstance(string $instanceId, string $reason): int;

	/**
	 * Suspend the beslistermijn timer (opschorting, AWB 4:5).
	 *
	 * The engine banks the consumed budget: while suspended the timer
	 * neither fires nor escalates nor reports overdue, and resuming
	 * re-projects from the unconsumed remainder.
	 *
	 * @param array<string, mixed> $instance The TermijnInstance row (carries `engineTimerId`).
	 * @param string $reason The opschorting rationale (recorded as evidence).
	 * @param DateTimeImmutable|null $until The expected pause end, display-only.
	 *
	 * @return bool True when the engine suspended the timer.
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function suspendBeslistermijn(array $instance, string $reason, ?DateTimeImmutable $until): bool;

	/**
	 * The call every term site makes: roll this end date if the term declares it.
	 *
	 * @param DateTimeImmutable    $date         The computed end date.
	 * @param array<string, mixed> $definitie    The term definition, when one is known.
	 * @param string|null          $calendarSlug The calendar named on the term, when any.
	 * @param string|null          $organisation The subject's organisation, when any.
	 *
	 * @return DateTimeImmutable The day the term actually ends on.
	 *
	 * @spec openspec/specs/termijnbewaking-schemas/spec.md
	 */
	public function rollTermEndFor(
		DateTimeImmutable $date,
		array $definitie = [],
		?string $calendarSlug = null,
		?string $organisation = null,
	): DateTimeImmutable;
}//end interface
