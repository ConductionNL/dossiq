<?php

/**
 * Contract of GemachtigdeRoleTypeSeeder
 *
 * The public surface of GemachtigdeRoleTypeSeeder that other components call, so they depend
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
 * Contract of GemachtigdeRoleTypeSeeder.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface GemachtigdeRoleTypeSeederInterface {

	/**
	 * Make sure exactly one generic Gemachtigde role type exists.
	 *
	 * @return array{available: bool, created: int, adopted: int, kept: int, refused: string|null}
	 *                                                                                            What the seed did.
	 *
	 * @spec openspec/specs/roles-decisions/spec.md#requirement-every-case-type-offers-a-gemachtigde-role-req-role-009
	 */
	public function seed(): array;
}//end interface
