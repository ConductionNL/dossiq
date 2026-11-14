<?php

/**
 * Contract of ConnectionTestService
 *
 * The public surface of ConnectionTestService that other components call, so they depend
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
 * Contract of ConnectionTestService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ConnectionTestServiceInterface {

	/**
	 * The endpoint did not answer, or answered an error.
	 */
	public const FAILED = 'failed';

	/**
	 * Probe one endpoint.
	 *
	 * @param string $endpoint The URL to call.
	 *
	 * @return array{state: string, endpoint: string, status: int, reason: string, measuredAt: string, responseTimeMs: int}
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/admin-settings/spec.md
	 */
	public function probe(string $endpoint): array;
}//end interface
