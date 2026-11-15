<?php

/**
 * Contract of TenantContext
 *
 * The public surface of TenantContext that other components call, so they depend
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
 * Contract of TenantContext.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TenantContextInterface {

	/**
	 * Get the organisation's lifecycle status, as stored in OpenRegister.
	 *
	 * @return string
	 *
	 * @throws RuntimeException When the request has no tenant.
	 *
	 * @spec openspec/specs/tenant-organisation-boundary/spec.md
	 */
	public function getStatus(): string;

	/**
	 * Get the tenant UUID.
	 *
	 * @return string
	 *
	 * @throws RuntimeException When the request has no tenant.
	 *
	 * @spec openspec/specs/tenant-organisation-boundary/spec.md
	 */
	public function getTenantId(): string;

	/**
	 * Whether the request has a tenant.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/tenant-organisation-boundary/spec.md
	 */
	public function isBound(): bool;
}//end interface
