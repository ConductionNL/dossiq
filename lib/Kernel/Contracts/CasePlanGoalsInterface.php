<?php

/**
 * Contract of CasePlanGoals
 *
 * The public surface of CasePlanGoals that other components call, so they depend
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
 * Contract of CasePlanGoals.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CasePlanGoalsInterface {

	/**
	 * The schema slug goals are stored under.
	 *
	 * @var string
	 */
	public const SCHEMA = 'casePlanGoal';

	/**
	 * Close a goal against the thing it said would count as met.
	 *
	 * The observation is REQUIRED, and it is the reason this is a method rather
	 * than a state write: closing a goal with nothing written beside it leaves
	 * a record that says a household reached something, with no way to know
	 * what anybody saw.
	 *
	 * @param string $goalId      The goal uuid.
	 * @param string $state       `met`, `not-met` or `withdrawn`.
	 * @param string $observation What was seen.
	 * @param string $onDate      The day, `Y-m-d`, or '' for today.
	 *
	 * @return array<string, mixed> The closed goal.
	 *
	 * @throws RefusedException When the goal cannot be read, the state is not a closing one, or nothing was observed.
	 *
	 * @spec openspec/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-a-case-plan-holds-interventions-with-a-goal-a-provider-and-dates-req-cpn-01
	 */
	public function close(string $goalId, string $state, string $observation, string $onDate = ''): array;

	/**
	 * Close a goal of one case, as close() does.
	 *
	 * A goal that belongs to another case, or to a family plan only, is
	 * answered as not found: the caller's case access says nothing about it.
	 *
	 * @param string $caseId      The case the goal must belong to.
	 * @param string $goalId      The goal uuid.
	 * @param string $state       `met`, `not-met` or `withdrawn`.
	 * @param string $observation What was seen.
	 *
	 * @return array<string, mixed> The closed goal.
	 *
	 * @throws RefusedException When the goal is not this case's, the state is not a closing one, or nothing was observed.
	 *
	 * @spec openspec/changes/case-goals/tasks.md#2.1
	 */
	public function closeOfCase(string $caseId, string $goalId, string $state, string $observation): array;

	/**
	 * The goals that belong to one case directly.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<int, array<string, mixed>> The goals.
	 *
	 * @throws RefusedException When the store cannot be read.
	 *
	 * @spec openspec/changes/case-goals/specs/case-goals/spec.md#requirement-a-case-can-hold-goals-that-say-what-counts-as-met
	 */
	public function ofCase(string $caseId): array;

	/**
	 * The goals of one plan.
	 *
	 * @param string $planId The gezinsplan uuid.
	 *
	 * @return array<int, array<string, mixed>> The goals.
	 *
	 * @throws RefusedException When the store cannot be read.
	 *
	 * @spec openspec/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-a-case-plan-holds-interventions-with-a-goal-a-provider-and-dates-req-cpn-01
	 */
	public function ofPlan(string $planId): array;

	/**
	 * Save a goal, refusing one that names nothing measurable.
	 *
	 * @param array<string, mixed> $goal The goal.
	 *
	 * @return array<string, mixed> The stored goal.
	 *
	 * @throws RefusedException When the goal is not evaluable, or the write fails.
	 *
	 * @spec openspec/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-a-case-plan-holds-interventions-with-a-goal-a-provider-and-dates-req-cpn-01
	 * @spec openspec/changes/case-goals/specs/case-goals/spec.md#requirement-a-case-can-hold-goals-that-say-what-counts-as-met
	 */
	public function save(array $goal): array;
}//end interface
