<?php

/**
 * Contract of CaseRoleProjection
 *
 * The public surface of CaseRoleProjection that other components call, so they depend
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
 * Contract of CaseRoleProjection.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseRoleProjectionInterface {

	/**
	 * Keep the role record of one link: create it, or bring it up to date.
	 *
	 * @param array<string, mixed> $link The link as OpenRegister stores it.
	 *
	 * @return string The role record's uuid, '' when the link produced none.
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-req-poc-001-a-person-linked-to-a-case-shall-become-a-role-record-on-that-case
	 */
	public function project(array $link): string;

	/**
	 * Remove the role record of a link that is gone.
	 *
	 * @param array<string, mixed> $link The link as it was.
	 *
	 * @return bool True when a record was removed.
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-req-poc-001-a-person-linked-to-a-case-shall-become-a-role-record-on-that-case
	 */
	public function retire(array $link): bool;
}//end interface
