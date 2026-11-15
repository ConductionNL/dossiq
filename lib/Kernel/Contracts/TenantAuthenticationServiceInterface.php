<?php

/**
 * Contract of TenantAuthenticationService
 *
 * The public surface of TenantAuthenticationService that other components call, so they depend
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
 * Contract of TenantAuthenticationService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TenantAuthenticationServiceInterface {

	/**
	 * Validate a tenant action against the active mandate matrix.
	 *
	 * @param string $tenantId Tenant UUID.
	 * @param string $userId NC user ID.
	 * @param string $action Requested action (create|edit|status_update|delete|...).
	 *
	 * @return array{allowed: bool, reason: string} Decision payload.
	 *
	 * @throws RefusedException When the matrix could not be read, so the answer is neither yes nor no.
	 *
	 * @spec openspec/specs/tenant-mandate/spec.md#requirement-mandate-matrix-validation-per-action-req-002-d-req-006-d
	 * @spec openspec/specs/quality-gates/spec.md
	 */
	public function validateMandateMatrix(string $tenantId, string $userId, string $action): array;
}//end interface
