<?php

/**
 * Contract of AiOversightDelegationService
 *
 * The public surface of AiOversightDelegationService that other components call, so they depend
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
 * Contract of AiOversightDelegationService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AiOversightDelegationServiceInterface {

	/**
	 * Send one human-oversight decision to hermiq.
	 *
	 * @param array<string, mixed> $entry A procest AI audit entry that carries a `userAction`.
	 *
	 * @return boolean True when hermiq recorded it; false when hermiq is absent or refused.
	 *
	 * @spec openspec/changes/page-topology-cleanup/specs/ai-oversight-surface/spec.md
	 */
	public function delegate(array $entry): bool;
}//end interface
