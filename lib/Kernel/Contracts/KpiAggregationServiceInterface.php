<?php

/**
 * Contract of KpiAggregationService
 *
 * The public surface of KpiAggregationService that other components call, so they depend
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
 * Contract of KpiAggregationService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface KpiAggregationServiceInterface {

	/**
	 * Compute every dashboard KPI for one user.
	 *
	 * The keys are the contract REQ-DASH-001 reads: a count per card, plus the
	 * secondary number each card's sub-label needs.
	 *
	 * @param string $userId The user whose task counts these are.
	 *
	 * @return array<string, mixed> The KPI payload.
	 *
	 * @spec openspec/specs/dashboard/spec.md#REQ-DASH-001
	 */
	public function computeKpis(string $userId): array;
}//end interface
