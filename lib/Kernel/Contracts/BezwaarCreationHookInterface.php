<?php

/**
 * Contract of BezwaarCreationHook
 *
 * The public surface of BezwaarCreationHook that other components call, so they depend
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
 * Contract of BezwaarCreationHook.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BezwaarCreationHookInterface {

	/**
	 * Link a bezwaar case to its primair besluit and create the objection.
	 *
	 * @param string $objectionCaseId UUID of the bezwaar case.
	 * @param string $contestedDecisionId UUID of the contested
	 *                                    primair besluit decision.
	 * @param array<string, mixed> $objectionPayload Optional extra objection
	 *                                               fields (grounds,
	 *                                               requestedRelief,
	 *                                               receivedDate, ...).
	 *
	 * @return array<string, mixed> The created objection record.
	 *
	 * @throws RuntimeException When OpenRegister or schemas are unavailable,
	 *                          or the contested decision cannot be resolved.
	 *
	 * @spec openspec/specs/bezwaar-beroep-workflow/spec.md
	 */
	public function onBezwaarCreated(
		string $objectionCaseId,
		string $contestedDecisionId,
		array $objectionPayload = [],
	): array;
}//end interface
