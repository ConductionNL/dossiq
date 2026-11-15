<?php

/**
 * Contract of CallbackService
 *
 * The public surface of CallbackService that other components call, so they depend
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
 * Contract of CallbackService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CallbackServiceInterface {

	/**
	 * Cancel a callback request owned by the agent.
	 *
	 * @param string $id The callback id.
	 * @param string $agentId The authenticated agent's user id.
	 * @param bool $isPrivileged Whether ownership is bypassed.
	 *
	 * @return array<string, mixed> The cancelled callback record.
	 *
	 * @throws OCSBadRequestException When not found or not owned.
	 *
	 * @spec openspec/specs/kcc-klantcontact-integratie/spec.md#requirement-callback-scheduling-and-sla-tracking
	 */
	public function cancel(string $id, string $agentId, bool $isPrivileged = false): array;

	/**
	 * List callback requests, scoped to the agent unless privileged.
	 *
	 * @param array<string, mixed> $filters Optional status filter.
	 * @param string $agentId The authenticated agent's user id.
	 * @param bool $isPrivileged Whether the caller may see all callbacks.
	 *
	 * @return array<int, array<string, mixed>> The callback requests.
	 *
	 * @spec openspec/specs/kcc-klantcontact-integratie/spec.md#requirement-callback-scheduling-and-sla-tracking
	 */
	public function list(array $filters, string $agentId, bool $isPrivileged = false): array;

	/**
	 * Schedule a new callback request.
	 *
	 * @param array<string, mixed> $data The request data.
	 * @param string $agentId The authenticated agent's user id.
	 *
	 * @return array<string, mixed> The saved callback request.
	 *
	 * @throws OCSBadRequestException When validation fails or storage is unavailable.
	 *
	 * @spec openspec/specs/kcc-klantcontact-integratie/spec.md#requirement-callback-scheduling-and-sla-tracking
	 */
	public function schedule(array $data, string $agentId): array;
}//end interface
