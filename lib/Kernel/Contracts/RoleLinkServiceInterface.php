<?php

/**
 * Contract of RoleLinkService
 *
 * The public surface of RoleLinkService that other components call, so they depend
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

use InvalidArgumentException;
use RuntimeException;

/**
 * Contract of RoleLinkService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RoleLinkServiceInterface {

	/**
	 * Link a case with a role to the target the caller names.
	 *
	 * @param string               $caseId   The case being linked.
	 * @param string               $role     The role, as the case type declares it.
	 * @param string               $targetId The case or object the link points at.
	 * @param array<string, mixed> $payload  Extra fields for the record the role opens.
	 *
	 * @return array{linkedCase: string, record: array<string, mixed>|null} What was written.
	 *
	 * @throws InvalidArgumentException When the role is not declared or the target is unusable.
	 * @throws RuntimeException When the register cannot be reached or a write is refused.
	 *
	 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md#requirement-linking-a-case-with-a-role-writes-the-relation-and-the-record-req-lcr-2
	 */
	public function link(string $caseId, string $role, string $targetId, array $payload = []): array;

	/**
	 * The roles a case's case type declares, and which required ones are missing.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array{roles: array<int, array<string, mixed>>, missing: array<int, string>} The roles.
	 *
	 * @throws RuntimeException When the case or its case type cannot be read.
	 *
	 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md#requirement-the-case-says-which-required-roles-are-missing-req-lcr-3
	 */
	public function rolesFor(string $caseId): array;
}//end interface
