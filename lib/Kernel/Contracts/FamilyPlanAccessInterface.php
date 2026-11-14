<?php

/**
 * Contract of FamilyPlanAccess
 *
 * The public surface of FamilyPlanAccess that other components call, so they depend
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
 * Contract of FamilyPlanAccess.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FamilyPlanAccessInterface {

	/**
	 * The status a read of a case's plan answers before any plan is read.
	 *
	 * @param string $caseId The case uuid from the route.
	 *
	 * @return int The HTTP status.
	 *
	 * @spec openspec/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-a-family-plan-is-read-only-by-whoever-may-read-its-case
	 */
	public function caseRead(string $caseId): int;

	/**
	 * The signed-in user's id, or '' without a session.
	 *
	 * @return string The uid.
	 *
	 * @spec openspec/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-a-write-on-a-family-plan-needs-the-right-to-change-its-case
	 */
	public function currentUid(): string;

	/**
	 * The status an intervention's goal answers before the intervention is
	 * written: 200 when it names no goal or a goal of this plan, 404 when the
	 * goal is not there or belongs to another plan or to a case, so an
	 * intervention never serves another family's goal.
	 *
	 * @param string $planId The gezinsplan uuid from the route.
	 * @param string $goalId The goal the intervention names, or ''.
	 *
	 * @return int The HTTP status.
	 *
	 * @throws RefusedException When the store cannot be read.
	 *
	 * @spec openspec/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-an-intervention-serves-a-goal-of-its-own-plan
	 */
	public function goalOnPlan(string $planId, string $goalId): int;

	/**
	 * The status closing a goal answers before it runs: 404 for a goal that is
	 * not there, else the status of the check on the goal's case.
	 *
	 * @param string $goalId The goal uuid.
	 *
	 * @return int The HTTP status.
	 *
	 * @throws RefusedException When the store cannot be read.
	 *
	 * @spec openspec/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-a-write-on-a-family-plan-needs-the-right-to-change-its-case
	 */
	public function goalWrite(string $goalId): int;

	/**
	 * The status a read of a plan answers before the plan is shown: 200 when
	 * it may be read, 404 for a plan that is not there, else the status of
	 * the read check on the plan's case.
	 *
	 * @param string $planId The gezinsplan uuid.
	 *
	 * @return int The HTTP status.
	 *
	 * @throws RefusedException When the store cannot be read.
	 *
	 * @spec openspec/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-a-family-plan-is-read-only-by-whoever-may-read-its-case
	 */
	public function planRead(string $planId): int;

	/**
	 * The status a write on a plan answers before it runs: 200 when it may run,
	 * 404 for a plan that is not there, else the status of the case check.
	 *
	 * @param string $planId       The gezinsplan uuid from the route.
	 * @param string $recordSchema The schema of the record the write names by id, or ''.
	 * @param string $recordId     The id of the record the write names, or ''.
	 *
	 * @return int The HTTP status.
	 *
	 * @throws RefusedException When the store cannot be read.
	 *
	 * @spec openspec/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-a-write-on-a-family-plan-needs-the-right-to-change-its-case
	 */
	public function planWrite(string $planId, string $recordSchema = '', string $recordId = ''): int;
}//end interface
