<?php

/**
 * Contract of ContactMomentService
 *
 * The public surface of ContactMomentService that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of ContactMomentService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ContactMomentServiceInterface {

	/**
	 * Create a contactmoment.
	 *
	 * A contact logged from the case page arrives with the channel, the
	 * direction, the summary and `case` only: the KCC fields the schema
	 * requires are defaulted here, and `relatedCases` is seeded from `case`
	 * so the KCC voorblad lists the contact as before.
	 *
	 * @param array<string, mixed> $data The contactmoment fields.
	 *
	 * @return array<string, mixed> The created contactmoment record.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable, schema unconfigured, or input invalid.
	 *
	 * @spec openspec/specs/kcc-werkplek-zaaksysteem-bridge/spec.md
	 */
	public function createContactMoment(array $data): array;

	/**
	 * List contactmomenten for an identified burger, most recent first.
	 *
	 * @param string $burgerId The identified burger reference.
	 * @param int $limit Maximum number of records.
	 *
	 * @return array<int, array<string, mixed>> The contactmoment records.
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T04
	 */
	public function listForBurger(string $burgerId, int $limit = 50): array;

	/**
	 * Append an immutable activity entry to a case's activity array.
	 *
	 * Activity entries are append-only: this reads the case's current activity
	 * list, appends a timestamped entry, and writes the merged list back. Prior
	 * entries are never edited or removed.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $interactionId The contactmoment UUID.
	 * @param string $type The activity type.
	 * @param string $employeeName The handling medewerker.
	 * @param string $summary A short summary of the activity.
	 *
	 * @return bool True on success.
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T04
	 */
	public function recordActivity(
		string $caseId,
		string $interactionId,
		string $type,
		string $employeeName,
		string $summary,
	): bool;
}//end interface
