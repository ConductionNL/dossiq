<?php

/**
 * Contract of CaseReassignmentService
 *
 * The public surface of CaseReassignmentService that other components call, so they depend
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

use InvalidArgumentException;

/**
 * Contract of CaseReassignmentService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseReassignmentServiceInterface {

	/**
	 * Preview the open cases and tasks that a reassignment from a handler would
	 * affect. Strictly read-only.
	 *
	 * @param string $fromUser The departing handler user id.
	 * @param array<string, mixed>|null $filter Optional filter, e.g. ['caseType' => 'uuid'].
	 *
	 * @return array{cases: array<int, array<string, mixed>>, tasks: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	public function preview(string $fromUser, ?array $filter = null): array;

	/**
	 * Release a handler's caseload, as one bulk job.
	 *
	 * Builds the selection and hands it over. There is no loop here: the job
	 * walks the cases, records what it did to each and reports it, and a
	 * coordinator commits it after reading the rehearsal (D-6). What comes
	 * back is the PREVIEWED job; nothing on a case has been written yet.
	 *
	 * ⚠️ The departing handler's open TASKS are moved straight away, in this
	 * call, and are not part of the job. A flow task is the engine's own
	 * record with its own audit row and its own `reassign` verb: it is not an
	 * object in the case register, so the job cannot walk it. Leaving the
	 * tasks behind until somebody commits is the failure this gesture exists
	 * to prevent, so they move with the request that orders the release.
	 *
	 * @param string                    $fromUser      The departing handler.
	 * @param string                    $toUser        The receiving handler.
	 * @param string                    $justification Why the caseload is being moved.
	 * @param array<string, mixed>|null $filter        Optional filter, e.g. ['caseType' => 'uuid'].
	 * @param string                    $actorId       The acting coordinator.
	 * @param bool                      $commit        Whether to start the act, or leave it rehearsed.
	 *
	 * @return array{job: array<string, mixed>, tasks: array<int, array<string, mixed>>, caseIds: array<int, string>}
	 *         The job over the cases, what happened to the tasks, and which cases the act was ordered over.
	 *
	 * @throws InvalidArgumentException When a handler, the receiver or the reason is missing.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The two gestures differ in
	 * exactly this: a coordinator RELEASING a caseload reads the rehearsal and
	 * decides, an administrator handing over a leaver's work is executing an
	 * act somebody already decided. Splitting them into two methods would
	 * duplicate the selection build, which is the part that must not drift.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function releaseCaseload(
		string $fromUser,
		string $toUser,
		string $justification,
		?array $filter = null,
		string $actorId = '',
		bool $commit = false,
	): array;
}//end interface
