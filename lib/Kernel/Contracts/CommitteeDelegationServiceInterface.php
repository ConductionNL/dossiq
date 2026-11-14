<?php

/**
 * Contract of CommitteeDelegationService
 *
 * The public surface of CommitteeDelegationService that other components call, so they depend
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
 * Contract of CommitteeDelegationService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CommitteeDelegationServiceInterface {

	/**
	 * Cause a GovernanceBody for this committee to exist, and return its id.
	 *
	 * Safe to call repeatedly: the other side resolves on
	 * (sourceApp, externalReference) before writing, so a second call updates
	 * the same body rather than minting another.
	 *
	 * @param array<string, mixed> $committee A local bezwaaradviescommissie row.
	 * @param string               $actorId   The acting Nextcloud UID.
	 *
	 * @return string The GovernanceBody id.
	 *
	 * @throws RuntimeException When the decision app is absent or refused.
	 *
	 * @spec openspec/changes/migrate-committees-to-decidiq/specs/migrate-committees-to-decidiq/spec.md
	 */
	public function ensureGovernanceBody(array $committee, string $actorId = ''): string;

	/**
	 * Whether the decision app is installed and exposes the command seam.
	 *
	 * @return boolean True when a command can be dispatched.
	 *
	 * @spec openspec/changes/migrate-committees-to-decidiq/specs/migrate-committees-to-decidiq/spec.md
	 */
	public function isAvailable(): bool;
}//end interface
