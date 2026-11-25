<?php

/**
 * Contract of SessionService
 *
 * The public surface of SessionService that other components call, so they depend
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

/**
 * Contract of SessionService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SessionServiceInterface {

	/**
	 * The sessions a case's case type declares, and the sessions it holds.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array{definitions: array<int, array<string, mixed>>, sessions: array<int, array<string, mixed>>} The sessions.
	 *
	 * @throws \RuntimeException When the case or its case type cannot be read.
	 *
	 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md#requirement-a-case-type-declares-the-sessions-a-case-holds-req-css-1
	 */
	public function sessionsFor(string $caseId): array;

	/**
	 * Plan a session the case type declares.
	 *
	 * @param string               $caseId        The case.
	 * @param string               $sessionKey    The `sessions[]` entry.
	 * @param string               $scheduledDate ISO-8601 start.
	 * @param string               $chairpersonId Who chairs the session.
	 * @param array<int, mixed>    $invitees      The invited parties, each {role, name?, channel?}.
	 * @param array<string, mixed> $payload       Extra fields (location, videoCallUrl, members, ...).
	 *
	 * @return array<string, mixed> The stored session.
	 *
	 * @throws \InvalidArgumentException When the session is not declared or the plan breaks its rules.
	 * @throws \RuntimeException When the register cannot be reached or refuses the write.
	 *
	 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md#requirement-planning-a-session-keeps-the-inspection-floor-and-the-invitee-roles-req-css-2
	 */
	public function schedule(
		string $caseId,
		string $sessionKey,
		string $scheduledDate,
		string $chairpersonId,
		array $invitees,
		array $payload = [],
	): array;
}//end interface
