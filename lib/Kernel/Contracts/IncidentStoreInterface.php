<?php

/**
 * Contract of IncidentStore
 *
 * The public surface of IncidentStore that other components call, so they depend
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

use OCA\Dossiq\Exception\RefusedException;

/**
 * Contract of IncidentStore.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface IncidentStoreInterface {

	/**
	 * Hand one incident to somebody, leaving the case where it is.
	 *
	 * @param string $incidentId The incident.
	 * @param string $assignee   Who takes it.
	 *
	 * @return array<string, mixed> The stored incident.
	 *
	 * @throws RefusedException When the incident is unknown or cannot be written.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-an-incident-carries-its-own-hand-off-req-cm-54
	 */
	public function assign(string $incidentId, string $assignee): array;

	/**
	 * The incidents on a case, in the order the record puts them.
	 *
	 * @param string $caseId The case.
	 *
	 * @return array<int, array<string, mixed>> The incidents.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-a-case-holds-several-dated-incidents-req-cm-53
	 */
	public function onCase(string $caseId): array;

	/**
	 * Record one report on a case.
	 *
	 * @param string $caseId      The case it happened inside.
	 * @param string $eventDate   When it happened, in ISO 8601.
	 * @param string $description What happened.
	 * @param string $reporter    Who reported it.
	 * @param string $assignee    Who will work it, or an empty string.
	 *
	 * @return array<string, mixed> The stored incident.
	 *
	 * @throws RefusedException When the report is incomplete or cannot be written.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-a-case-holds-several-dated-incidents-req-cm-53
	 */
	public function record(
		string $caseId,
		string $eventDate,
		string $description,
		string $reporter = '',
		string $assignee = '',
	): array;

	/**
	 * How many days late one report was written down.
	 *
	 * A pass-through, so a caller holding this store does not also have to hold
	 * the record to print the delay beside a row.
	 *
	 * @param array<string, mixed> $incident The incident.
	 *
	 * @return int|null The delay in days, or null when either moment is missing.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-a-case-holds-several-dated-incidents-req-cm-53
	 */
	public function recordingDelayDays(array $incident): ?int;

	/**
	 * Settle one incident with its outcome.
	 *
	 * @param string $incidentId The incident.
	 * @param string $outcome    What came of it.
	 *
	 * @return array<string, mixed> The stored incident.
	 *
	 * @throws RefusedException When the incident is unknown, the outcome is empty, or the write fails.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-a-case-holds-several-dated-incidents-req-cm-53
	 */
	public function settle(string $incidentId, string $outcome): array;
}//end interface
