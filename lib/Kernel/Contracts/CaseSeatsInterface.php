<?php

/**
 * Contract of CaseSeats
 *
 * The public surface of CaseSeats that other components call, so they depend
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
 * Contract of CaseSeats.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseSeatsInterface {

	/**
	 * Empty the coordinator seat, and say who held it.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return string The uid that came off the seat, '' when it was already empty.
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-a-case-carries-a-handler-and-a-coordinator-req-hand-05
	 */
	public function clearCoordinator(string $caseId): string;

	/**
	 * Name the coordinator on a case, replacing whoever held the seat.
	 *
	 * @param string $caseId      The case uuid.
	 * @param string $participant The person, as a uid or a `user:<uid>` reference.
	 * @param string $displayName What to call them on the People tab.
	 *
	 * @return string The role record's uuid.
	 *
	 * @throws RuntimeException When the instance declares no coordinator role type.
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-a-case-carries-a-handler-and-a-coordinator-req-hand-05
	 */
	public function nameCoordinator(string $caseId, string $participant, string $displayName = ''): string;

	/**
	 * Both seats on one case.
	 *
	 * @param array<string, mixed> $case The stored case.
	 *
	 * @return array{handler: string, coordinator: string, coordinatorRole: string}
	 *         The handler uid, the coordinator uid, and the uuid of the role
	 *         record holding the coordinator seat ('' when the seat is empty).
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-a-case-carries-a-handler-and-a-coordinator-req-hand-05
	 */
	public function seatsOf(array $case): array;
}//end interface
