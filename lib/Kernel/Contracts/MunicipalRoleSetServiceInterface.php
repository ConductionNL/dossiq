<?php

/**
 * Contract of MunicipalRoleSetService
 *
 * The public surface of MunicipalRoleSetService that other components call, so they depend
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
 * Contract of MunicipalRoleSetService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MunicipalRoleSetServiceInterface {

	/**
	 * Take the set into use.
	 *
	 * @return array{ok: bool, reason: string, adopted: int} What happened.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	public function adopt(): array;

	/**
	 * What the roles screen should show: the set, whether it is adopted, and
	 * whether anything has been granted against it.
	 *
	 * @return array<string, mixed>|null The offer, or null when unreachable.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	public function offer(): ?array;

	/**
	 * Put the set back to dormant, while nothing is granted against it.
	 *
	 * @return array{ok: bool, reason: string, roleInUse: string} What happened,
	 *         and which role stopped it when it did not.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	public function undoAdoption(): array;
}//end interface
