<?php

/**
 * Contract of IntakeTermStart
 *
 * The public surface of IntakeTermStart that other components call, so they depend
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
 * Contract of IntakeTermStart.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface IntakeTermStartInterface {

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
	public function isStamped(array $case): bool;

	/**
	 * The stamp for a case, read from the receipt the case itself names.
	 *
	 * A receipt known only to the day (an imported `receiptDate` answer such
	 * as `2026-10-06`) reads as midnight, and comparing instants would call
	 * every such receipt outside working hours: a claim nobody computed. For
	 * that receipt the flag compares days instead.
	 *
	 * @param array<string, mixed> $case The case, as just created.
	 *
	 * @return array<string, mixed> The three fields, or [].
	 *
	 * @spec openspec/specs/burger-notifications/spec.md#requirement-dossiq-sets-the-receipt-and-start-dates-when-a-case-is-created-req-term-042
	 */
	public function stampForCase(array $case): array;

	/**
	 * The moment a case's term starts: its `termStartsAt`.
	 *
	 * THE STAMP IS NOT YET ON A NEW CASE, as a rule. `IntakeTermStartListener`
	 * stamps the case on the same create event as the term is bound on, and
	 * runs after the bind, so reading the stamp back would find nothing. The
	 * same calendar question is asked here instead, from the same arrival
	 * moment, which is what keeps the term and the stamp equal: one calendar,
	 * asked once each, the same way.
	 *
	 * When no calendar answers, the arrival moment itself and never "now": a
	 * case registered on Tuesday for a request received on Sunday is owed the
	 * days since Sunday.
	 *
	 * @param array<string, mixed> $case The case, as stored or as just created.
	 *
	 * @return DateTimeImmutable When its term starts.
	 *
	 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md#requirement-the-statutory-term-counts-from-receipt-req-ote-02
	 */
	public function termStartFor(array $case): DateTimeImmutable;
}//end interface
