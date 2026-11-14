<?php

/**
 * Contract of PermitChangeRefused
 *
 * The public surface of PermitChangeRefused that other components call, so they depend
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
 * Contract of PermitChangeRefused.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PermitChangeRefusedInterface {

	/**
	 * The request cannot be used.
	 */
	public const INVALID = 'invalid';

	/**
	 * The permit does not exist or is not the resident's.
	 */
	public const NOT_FOUND = 'not_found';

	/**
	 * The permit is suspended or revoked.
	 */
	public const NOT_ACTIVE = 'not_active';

	/**
	 * The register or the change case type is missing.
	 */
	public const UNAVAILABLE = 'unavailable';

	/**
	 * @return string The sentence.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function getDetail(): string;

	/**
	 * @return string The reason code.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function getReason(): string;
}//end interface
