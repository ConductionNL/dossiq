<?php

/**
 * Contract of SamenwerkverzoekService
 *
 * The public surface of SamenwerkverzoekService that other components call, so they depend
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

use OCP\IUser;

/**
 * Contract of SamenwerkverzoekService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SamenwerkverzoekServiceInterface {

	/**
	 * Authorise a samenwerkverzoek mutation.
	 *
	 * Only administrators are permitted to modify samenwerkverzoek objects
	 * per VTH inter-authority collaboration policy.
	 *
	 * @param array<string,mixed> $samenwerk The samenwerkverzoek object array
	 * @param IUser $user The authenticated user
	 *
	 * @return void
	 *
	 * @throws \Exception When the user is not an administrator
	 *
	 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T05
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) — $samenwerk reserved for future ACL
	 */
	public function authorizeSamenwerkMutation(array $samenwerk, IUser $user): void;

	/**
	 * Initiate a samenwerking request for a zaak.
	 *
	 * Creates a samenwerkverzoek object with status 'requested' and
	 * dispatches a SamenwerkverzoekInitiated event for downstream listeners.
	 *
	 * @param string $caseId The UUID of the zaak
	 * @param string $aangezochtGezag The requested authority identifier
	 * @param string $rationale The reason for requesting cooperation
	 *
	 * @return array<string,mixed> The created samenwerkverzoek object
	 *
	 * @throws \RuntimeException When the zaak cannot be found
	 *
	 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T05
	 */
	public function initiateSamenwerking(
		string $caseId,
		string $aangezochtGezag,
		string $rationale,
	): array;

	/**
	 * Respond to a pending samenwerkverzoek.
	 *
	 * Validates that the verzoek is in 'requested' status, then updates
	 * it to 'accepted' or 'refused' with the provided advies text.
	 *
	 * @param string $samenwerkId The UUID of the samenwerkverzoek
	 * @param bool $accept True to accept, false to reject
	 * @param string $advies The advies/reasoning text for the response
	 *
	 * @return array<string,mixed> The updated samenwerkverzoek object
	 *
	 * @throws \RuntimeException When the verzoek cannot be found or is not in 'requested' status
	 *
	 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T05
	 */
	public function respondToSamenwerking(string $samenwerkId, bool $accept, string $advies): array;
}//end interface
