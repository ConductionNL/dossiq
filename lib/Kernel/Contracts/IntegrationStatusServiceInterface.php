<?php

/**
 * Contract of IntegrationStatusService
 *
 * The public surface of IntegrationStatusService that other components call, so they depend
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
 * Contract of IntegrationStatusService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface IntegrationStatusServiceInterface {

	/**
	 * Report a connection's status to integriq.
	 *
	 * Never throws: this runs beside a probe whose own answer is the thing the
	 * caller returns, and a report that cannot be delivered must not turn a
	 * successful connection test into a 500. Without integriq nothing is sent
	 * and nothing is logged, because a missing optional app is not a fault.
	 *
	 * @param string $key One of {@see self::KEYS}.
	 * @param string $status One of {@see self::STATUSES}.
	 * @param string $message What the probe reported.
	 *
	 * @return bool True when the report was sent.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function record(string $key, string $status, string $message = ''): bool;
}//end interface
