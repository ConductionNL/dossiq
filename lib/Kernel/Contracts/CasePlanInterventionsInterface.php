<?php

/**
 * Contract of CasePlanInterventions
 *
 * The public surface of CasePlanInterventions that other components call, so they depend
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
 * Contract of CasePlanInterventions.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CasePlanInterventionsInterface {

	/**
	 * The schema slug interventions are stored under.
	 *
	 * @var string
	 */
	public const SCHEMA = 'intervention';

	/**
	 * The interventions of one plan, each saying whether it is overdue.
	 *
	 * @param string $planId The gezinsplan uuid.
	 * @param string $today  The day to compare against, `Y-m-d`, or '' for today.
	 *
	 * @return array<int, array<string, mixed>> The interventions, with `overdue` on each.
	 *
	 * @throws RefusedException When the store cannot be read.
	 *
	 * @spec openspec/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-a-case-plan-holds-interventions-with-a-goal-a-provider-and-dates-req-cpn-01
	 */
	public function ofPlan(string $planId, string $today = ''): array;

	/**
	 * Save an intervention, refusing a provider that is a typed name.
	 *
	 * @param array<string, mixed> $intervention The intervention.
	 *
	 * @return array<string, mixed> The stored intervention.
	 *
	 * @throws RefusedException When the intervention is incomplete, or the write fails.
	 *
	 * @spec openspec/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-a-case-plan-holds-interventions-with-a-goal-a-provider-and-dates-req-cpn-01
	 */
	public function save(array $intervention): array;
}//end interface
