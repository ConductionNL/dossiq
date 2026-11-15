<?php

/**
 * Contract of EmployeeAlertAudience
 *
 * The public surface of EmployeeAlertAudience that other components call, so they depend
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
 * Contract of EmployeeAlertAudience.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EmployeeAlertAudienceInterface {

	/**
	 * Whether this user may read the employee threshold alerts.
	 *
	 * @param string $uid The caller's user id, '' for no session.
	 *
	 * @return bool True when the caller is in one of the audience groups.
	 *
	 * @spec openspec/specs/complaint-management/spec.md#scenario-employee-complaint-threshold-alert
	 */
	public function mayReadEmployeeAlerts(string $uid): bool;
}//end interface
