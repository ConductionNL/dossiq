<?php

/**
 * Contract of ContactMomentService
 *
 * The public surface of ContactMomentService that other components call, so they depend
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

use OCP\AppFramework\OCS\OCSBadRequestException;

/**
 * Contract of ContactMomentService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface KccContactMomentServiceInterface {

	/**
	 * List contact moments with optional filters.
	 *
	 * Non-privileged callers are scoped to their own handled moments
	 * (kccAgentRef) to prevent IDOR-style enumeration.
	 *
	 * @param array<string, mixed> $filters Optional channel/outcome/team filters.
	 * @param string $agentId The authenticated agent's user id.
	 * @param bool $isPrivileged Whether the caller may see all moments.
	 *
	 * @return array<int, array<string, mixed>> The contact moments.
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-klantcontact-integratie/tasks.md#TASK-KCC-02
	 */
	public function list(array $filters, string $agentId, bool $isPrivileged = false): array;

	/**
	 * Persist a new contact moment.
	 *
	 * @param array<string, mixed> $data The request data.
	 * @param string $agentId The authenticated agent's user id.
	 *
	 * @return array<string, mixed> The saved contact moment.
	 *
	 * @throws OCSBadRequestException When validation fails or storage is unavailable.
	 *
	 * @spec openspec/specs/kcc-klantcontact-integratie/spec.md#requirement-contactmoment-records-capture-full-interaction-context
	 */
	public function create(array $data, string $agentId): array;

	/**
	 * Find a single contact moment, enforcing ownership for non-privileged callers.
	 *
	 * @param string $id The contact moment id.
	 * @param string $agentId The authenticated agent's user id.
	 * @param bool $isPrivileged Whether the caller may see any moment.
	 *
	 * @return array<string, mixed> The contact moment.
	 *
	 * @throws OCSBadRequestException When not found or not owned.
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-klantcontact-integratie/tasks.md#TASK-KCC-02
	 */
	public function get(string $id, string $agentId, bool $isPrivileged = false): array;

	/**
	 * Update an existing contact moment owned by the agent.
	 *
	 * Enforces ownership: an agent may only update a contact moment they
	 * handle (kccAgentRef). Admins/managers are handled at the controller
	 * layer via NC's auth attributes.
	 *
	 * @param string $id The contact moment id.
	 * @param array<string, mixed> $data The fields to update.
	 * @param string $agentId The authenticated agent's user id.
	 * @param bool $isPrivileged Whether the caller bypasses ownership.
	 *
	 * @return array<string, mixed> The updated contact moment.
	 *
	 * @throws OCSBadRequestException When not found or not owned.
	 *
	 * @spec openspec/specs/kcc-klantcontact-integratie/spec.md#requirement-contactmoment-records-capture-full-interaction-context
	 */
	public function update(string $id, array $data, string $agentId, bool $isPrivileged = false): array;

	/**
	 * Find related contact moments for the same customer.
	 *
	 * @param string $id The reference contact moment id.
	 * @param string $agentId The authenticated agent's user id.
	 * @param bool $isPrivileged Whether the caller may see any moment.
	 *
	 * @return array<int, array<string, mixed>> Related contact moments.
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-klantcontact-integratie/tasks.md#TASK-KCC-02
	 */
	public function related(string $id, string $agentId, bool $isPrivileged = false): array;
}//end interface
